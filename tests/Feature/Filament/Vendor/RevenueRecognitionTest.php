<?php

use App\Actions\Finance\RecognizeOrderRevenueAction;
use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\OrderStatusService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

// Revenue for an online order is booked when GadgetPlug has the money —
// Paystack at paid, pay-on-delivery when an admin records the payment
// received — into the vendor's Due from GadgetPlug account. Delivered moves
// no money, and nothing in the vendor panel can book or record it.
// (Rewritten for Prompt 3 Phase 3; the old rules booked POD at delivered,
// into the vendor's own cash or bank, from the vendor panel.)

uses(RefreshDatabase::class);

function setUpRecognitionVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Recognition Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Recognition Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Recognition Product', 'price' => 5000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    return compact('owner', 'vendor', 'category', 'product', 'admin');
}

function makeRecognitionOrder(array $data, string $paymentMethod, string $status): Order
{
    $order = Order::create([
        'reference' => 'ORD-REC-' . uniqid(),
        'customer_name' => 'Jane Customer', 'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => 5000, 'status' => 'pending', 'payment_method' => $paymentMethod,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => 1, 'unit_price' => 5000,
    ]);

    // Walked through the service one hop at a time, as a real order moves.
    $service = app(OrderStatusService::class);
    $paystack = $paymentMethod === 'paystack';

    $paystack ? $service->markPaid($order) : $service->confirm($order);

    if (in_array($status, ['shipped', 'delivered'], true)) {
        $service->handOver($order, $data['owner']);
    }

    if ($status === 'delivered') {
        $service->markDelivered($order, $data['admin'], 'Customer confirmed');
    }

    return $order->fresh();
}

function recognitionDue(array $data): FinancialAccount
{
    return FinancialAccount::gadgetplugDueFor($data['vendor']->id);
}

function actAsRecognitionOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

function actAsRecognitionAdmin(array $data): void
{
    test()->actingAs($data['admin']);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

test('a prepaid order recognizes revenue into Due from GadgetPlug on the paid transition', function () {
    $data = setUpRecognitionVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();

    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('bank_transfer')
        ->and(recognitionDue($data)->balance())->toBe(5000.0)
        // Never the vendor's own bank: GadgetPlug holds the money.
        ->and($bank->fresh()->balance())->toBe(0.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('direction', 'in')->count())->toBe(1);
});

test('a POD order paid in cash recognizes revenue into Due when the admin records it', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    actAsRecognitionAdmin($data);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('recordPaymentReceived', data: ['payment_channel' => 'cash'])
        ->assertHasNoActionErrors();

    $order->refresh();

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('cash')
        ->and(recognitionDue($data)->balance())->toBe(5000.0)
        ->and($cash->fresh()->balance())->toBe(0.0);
});

test('a POD order paid by bank transfer records that channel, into Due', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    actAsRecognitionAdmin($data);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('recordPaymentReceived', data: ['payment_channel' => 'bank_transfer'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->payment_channel)->toBe('bank_transfer')
        ->and(recognitionDue($data)->balance())->toBe(5000.0);
});

test('the channel is required when recording a POD payment', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    actAsRecognitionAdmin($data);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('recordPaymentReceived', data: ['payment_channel' => null])
        ->assertHasActionErrors(['payment_channel']);

    expect($order->fresh()->isRevenueRecognized())->toBeFalse();
});

test('cancelled and paid_but_failed_stock orders never recognize revenue', function () {
    $dataA = setUpRecognitionVendor();
    $orderA = makeRecognitionOrder($dataA, 'pay_on_delivery', 'confirmed');
    app(OrderStatusService::class)->cancel($orderA, $dataA['admin'], 'Customer cancelled');

    $dataB = setUpRecognitionVendor();
    $orderB = Order::create([
        'reference' => 'ORD-FAIL-' . uniqid(), 'customer_name' => 'X', 'customer_email' => 'x@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Y', 'total_amount' => 5000,
        'status' => 'pending', 'payment_method' => 'paystack',
    ]);
    OrderItem::create(['order_id' => $orderB->id, 'product_id' => $dataB['product']->id, 'vendor_id' => $dataB['vendor']->id, 'quantity' => 1, 'unit_price' => 5000]);
    app(OrderStatusService::class)->markPaidButFailedStock($orderB);

    expect($orderA->fresh()->isRevenueRecognized())->toBeFalse()
        ->and($orderB->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('a delivered POD order recognizes nothing until its payment is recorded', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');

    expect($order->status)->toBe('delivered')
        ->and($order->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('a bare status write — outside the service — books nothing', function () {
    // The observer no longer moves money: only OrderStatusService does.
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'confirmed');

    $order->update(['status' => 'delivered', 'payment_channel' => 'cash']);

    expect($order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('a recognized order\'s payment channel cannot be changed directly on the model', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    app(OrderStatusService::class)->recordPaymentReceived($order, $data['admin'], 'cash');

    expect(fn () => $order->fresh()->update(['payment_channel' => 'bank_transfer']))
        ->toThrow(\LogicException::class);
});

test('the Meta Purchase path is untouched by recognition — the paid transition still completes', function () {
    $data = setUpRecognitionVendor();

    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect($order->status)->toBe('paid')
        ->and($order->isRevenueRecognized())->toBeTrue();
});

// ─── The vendor panel cannot set delivered or book money ─────────────

test('the orders list cannot mark a POD order delivered', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    actAsRecognitionOwner($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered');

    expect($order->fresh()->status)->toBe('shipped')
        ->and($order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('the orders list cannot mark a prepaid order delivered either', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'shipped');
    actAsRecognitionOwner($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered');

    expect($order->fresh()->status)->toBe('shipped');
});

test('the vendor order page has no record-payment action', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    actAsRecognitionOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionDoesNotExist('recordPaymentReceived');
});

// ─── Recording the payment (admin) ──────────────────────────────────

test('the admin record payment action disappears once revenue is recognized, so one sale can never be counted twice', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');
    app(OrderStatusService::class)->recordPaymentReceived($order, $data['admin'], 'cash');
    actAsRecognitionAdmin($data);

    Livewire::test(AdminViewOrder::class, ['record' => $order->fresh()->getRouteKey()])
        ->assertActionHidden('recordPaymentReceived');
});

test('the admin record payment action is offered once the order is handed over, and not before', function () {
    $data = setUpRecognitionVendor();
    $confirmed = makeRecognitionOrder($data, 'pay_on_delivery', 'confirmed');
    $shipped = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $paystack = makeRecognitionOrder($data, 'paystack', 'shipped');
    actAsRecognitionAdmin($data);

    Livewire::test(AdminViewOrder::class, ['record' => $confirmed->getRouteKey()])
        ->assertActionHidden('recordPaymentReceived');

    Livewire::test(AdminViewOrder::class, ['record' => $shipped->getRouteKey()])
        ->assertActionVisible('recordPaymentReceived');

    Livewire::test(AdminViewOrder::class, ['record' => $paystack->getRouteKey()])
        ->assertActionHidden('recordPaymentReceived');
});

test('recording a payment twice posts one entry, not two', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'delivered');

    app(RecognizeOrderRevenueAction::class)->execute($order->fresh(), 'cash');
    $secondAttempt = app(RecognizeOrderRevenueAction::class)->execute($order->fresh(), 'cash');

    expect($secondAttempt)->toBe('already_recognized')
        ->and(recognitionDue($data)->balance())->toBe(5000.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)->where('direction', 'in')->count())->toBe(1);
});

// ─── Cancellation reversal ──────────────────────────────────────────

test('cancelling a recognized prepaid order posts a reversing out entry and restores the balance', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect(recognitionDue($data)->balance())->toBe(5000.0);

    app(OrderStatusService::class)->cancel($order, $data['admin'], 'Refunded');

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();
    $reversal = FinancialLedgerEntry::where('source_type', $original->getMorphClass())
        ->where('source_id', $original->id)->where('direction', 'out')->first();

    expect($reversal)->not->toBeNull()
        ->and((float) $reversal->amount)->toBe(5000.0)
        ->and(recognitionDue($data)->balance())->toBe(0.0)
        // revenue_recognized_at is history, not cleared by the reversal.
        ->and($order->fresh()->isRevenueRecognized())->toBeTrue();
});

test('the original revenue entry is untouched by the reversal — still an unmodified in entry', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');
    app(OrderStatusService::class)->cancel($order, $data['admin'], 'Refunded');

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();

    expect($original->direction)->toBe('in')
        ->and((float) $original->amount)->toBe(5000.0);
});

test('cancelling an order that was never recognized posts no reversal', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'confirmed');

    app(OrderStatusService::class)->cancel($order, $data['admin'], 'Customer cancelled');

    expect(FinancialLedgerEntry::count())->toBe(0);
});

test('a cancelled order cannot be cancelled again, so it is never double-reversed', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');
    app(OrderStatusService::class)->cancel($order, $data['admin'], 'Refunded');

    expect(fn () => app(OrderStatusService::class)->cancel($order->fresh(), $data['admin'], 'Again'))
        ->toThrow(App\Services\Orders\InvalidOrderTransition::class);

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();

    expect(FinancialLedgerEntry::where('source_type', $original->getMorphClass())
        ->where('source_id', $original->id)->where('direction', 'out')->count())->toBe(1);
});
