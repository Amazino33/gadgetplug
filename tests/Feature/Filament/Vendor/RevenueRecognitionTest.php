<?php

use App\Actions\Finance\RecognizeOrderRevenueAction;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

    return compact('owner', 'vendor', 'category', 'product');
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

    // Walk it to the target status one hop at a time, same as a real order
    // would, so OrderObserver's own transition guards apply normally.
    $path = match ($paymentMethod . ':' . $status) {
        'pay_on_delivery:confirmed' => ['confirmed'],
        'pay_on_delivery:shipped'   => ['confirmed', 'shipped'],
        'pay_on_delivery:delivered' => ['confirmed', 'shipped'],
        'paystack:paid'             => ['paid'],
        'paystack:shipped'          => ['paid', 'shipped'],
        default                     => [$status],
    };

    foreach ($path as $step) {
        $order->update(['status' => $step]);
    }

    return $order->fresh();
}

function actAsRecognitionOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('a prepaid order auto-recognizes revenue to the bank account on the paid transition', function () {
    $data = setUpRecognitionVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();

    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('bank_transfer')
        ->and($bank->fresh()->balance())->toBe(5000.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('direction', 'in')->count())->toBe(1);
});

test('a POD order marked delivered with the cash channel recognizes revenue to the cash account', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    actAsRecognitionOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'delivered', 'notify_customer' => false, 'payment_channel' => 'cash'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $order->refresh();

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('cash')
        ->and($cash->fresh()->balance())->toBe(5000.0);
});

test('a POD order marked delivered with the bank transfer channel recognizes revenue to the bank account', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    actAsRecognitionOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'delivered', 'notify_customer' => false, 'payment_channel' => 'bank_transfer'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($order->fresh()->isRevenueRecognized())->toBeTrue()
        ->and($bank->fresh()->balance())->toBe(5000.0);
});

test('the channel prompt is required when marking a POD order delivered', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    actAsRecognitionOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'delivered', 'notify_customer' => false, 'payment_channel' => null])
        ->callMountedAction()
        ->assertHasActionErrors(['payment_channel']);

    expect($order->fresh()->isRevenueRecognized())->toBeFalse();
});

test('cancelled and paid_but_failed_stock orders never recognize revenue', function () {
    $dataA = setUpRecognitionVendor();
    $orderA = makeRecognitionOrder($dataA, 'pay_on_delivery', 'confirmed');
    $orderA->update(['status' => 'cancelled']);

    $dataB = setUpRecognitionVendor();
    $orderB = Order::create([
        'reference' => 'ORD-FAIL-' . uniqid(), 'customer_name' => 'X', 'customer_email' => 'x@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Y', 'total_amount' => 5000,
        'status' => 'pending', 'payment_method' => 'paystack',
    ]);
    OrderItem::create(['order_id' => $orderB->id, 'product_id' => $dataB['product']->id, 'vendor_id' => $dataB['vendor']->id, 'quantity' => 1, 'unit_price' => 5000]);
    $orderB->update(['status' => 'paid_but_failed_stock']);

    expect($orderA->fresh()->isRevenueRecognized())->toBeFalse()
        ->and($orderB->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('marking delivered without a captured channel recognizes nothing, does not error, and is safe to happen at all', function () {
    // Simulates one of the other three status-change entry points that
    // don't capture a channel (Orders list row action, ListOrders,
    // OrderItems) — a bare status update with no payment_channel.
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');

    $order->update(['status' => 'delivered']);

    expect($order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('redundantly re-saving the same delivered status does not double-post', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $order->update(['status' => 'delivered', 'payment_channel' => 'cash']);

    expect($order->fresh()->isRevenueRecognized())->toBeTrue();

    // Same status value again — wasChanged('status') is false, so this must
    // not re-trigger recognition or post a second entry.
    $order->fresh()->update(['status' => 'delivered']);

    expect(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('direction', 'in')->count())->toBe(1);
});

test('a recognized order\'s payment channel cannot be changed directly on the model', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $order->update(['status' => 'delivered', 'payment_channel' => 'cash']);

    expect(fn () => $order->fresh()->update(['payment_channel' => 'bank_transfer']))
        ->toThrow(\LogicException::class);
});

test('the Meta Purchase event path is untouched by recognition — the confirmed/paid transition still fires it', function () {
    // Not asserting the HTTP dispatch itself (out of scope, already tested
    // elsewhere) — just confirming applyRevenueRecognition() doesn't throw
    // or interfere when both it and applyMetaConversionEvents() run off the
    // same transition, since both are wired into the same observer method.
    $data = setUpRecognitionVendor();

    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect($order->status)->toBe('paid')
        ->and($order->isRevenueRecognized())->toBeTrue();
});

// ─── Every entry point captures the channel ─────────────────────────

test('the orders list refuses to mark a POD order delivered without saying how the customer paid', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    actAsRecognitionOwner($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered', null, null);

    // Status is left alone entirely — a delivered order with no money recorded
    // anywhere is exactly the state this refusal exists to prevent.
    expect($order->fresh()->status)->toBe('shipped')
        ->and($order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('the orders list recognizes revenue when the channel is given', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    actAsRecognitionOwner($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered', null, 'cash');

    $order->refresh();

    expect($order->status)->toBe('delivered')
        ->and($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('cash')
        ->and($cash->fresh()->balance())->toBe(5000.0);
});

test('a prepaid order is never blocked by the channel check — nothing to ask', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'shipped');
    actAsRecognitionOwner($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered', null, null);

    expect($order->fresh()->status)->toBe('delivered');
});

// ─── Manual recovery for money already earned ───────────────────────

test('the order page can record a payment for an order delivered without a channel', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();

    // The gap as it exists in production: delivered, but recognized nowhere.
    $order->update(['status' => 'delivered']);
    expect($order->fresh()->isRevenueRecognized())->toBeFalse();

    actAsRecognitionOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('recordPaymentReceived')
        ->setActionData(['payment_channel' => 'cash'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $order->refresh();

    expect($order->isRevenueRecognized())->toBeTrue()
        ->and($order->payment_channel)->toBe('cash')
        ->and($cash->fresh()->balance())->toBe(5000.0);
});

test('the record payment action disappears once revenue is recognized, so one sale can never be counted twice', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $order->update(['status' => 'delivered', 'payment_channel' => 'cash']);
    actAsRecognitionOwner($data);

    expect($order->fresh()->isRevenueRecognized())->toBeTrue();

    Livewire::test(ViewOrder::class, ['record' => $order->fresh()->getRouteKey()])
        ->assertActionHidden('recordPaymentReceived');
});

test('the record payment action is offered on an unrecognized order and hidden on a fresh one', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    actAsRecognitionOwner($data);

    // Still in transit — nothing to record yet.
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('recordPaymentReceived');

    $order->update(['status' => 'delivered']);

    Livewire::test(ViewOrder::class, ['record' => $order->fresh()->getRouteKey()])
        ->assertActionVisible('recordPaymentReceived');
});

test('recording a payment twice posts one entry, not two', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'shipped');
    $order->update(['status' => 'delivered']);
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();

    app(RecognizeOrderRevenueAction::class)->execute($order->fresh(), 'cash');
    $secondAttempt = app(RecognizeOrderRevenueAction::class)->execute($order->fresh(), 'cash');

    expect($secondAttempt)->toBe('already_recognized')
        ->and($cash->fresh()->balance())->toBe(5000.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)->where('direction', 'in')->count())->toBe(1);
});

// ─── Cancellation reversal ──────────────────────────────────────────

test('cancelling a recognized prepaid order posts a reversing out entry and restores the balance', function () {
    $data = setUpRecognitionVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');

    expect($bank->fresh()->balance())->toBe(5000.0);

    $order->update(['status' => 'cancelled']);

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();
    $reversal = FinancialLedgerEntry::where('source_type', $original->getMorphClass())
        ->where('source_id', $original->id)->where('direction', 'out')->first();

    expect($reversal)->not->toBeNull()
        ->and((float) $reversal->amount)->toBe(5000.0)
        ->and($bank->fresh()->balance())->toBe(0.0)
        // revenue_recognized_at is history, not cleared by the reversal.
        ->and($order->fresh()->isRevenueRecognized())->toBeTrue();
});

test('the original revenue entry is untouched by the reversal — still an unmodified in entry', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');
    $order->update(['status' => 'cancelled']);

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();

    expect($original->direction)->toBe('in')
        ->and((float) $original->amount)->toBe(5000.0);
});

test('cancelling an order that was never recognized posts no reversal', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'pay_on_delivery', 'confirmed');

    $order->update(['status' => 'cancelled']);

    expect(FinancialLedgerEntry::count())->toBe(0);
});

test('redundantly re-saving cancelled does not double-reverse', function () {
    $data = setUpRecognitionVendor();
    $order = makeRecognitionOrder($data, 'paystack', 'paid');
    $order->update(['status' => 'cancelled']);

    $order->fresh()->update(['status' => 'cancelled']);

    $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
        ->where('source_id', $order->id)->where('direction', 'in')->first();

    expect(FinancialLedgerEntry::where('source_type', $original->getMorphClass())
        ->where('source_id', $original->id)->where('direction', 'out')->count())->toBe(1);
});
