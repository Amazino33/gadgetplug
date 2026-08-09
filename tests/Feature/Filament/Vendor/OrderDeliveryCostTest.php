<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpDeliveryCostVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Delivery Cost Store ' . uniqid(), 'online_sales_enabled' => true]);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Delivery Cost Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Delivery Cost Product', 'price' => 5000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-DC-' . uniqid(),
        'customer_name' => 'Jane Customer', 'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => 5000, 'status' => 'paid', 'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 5000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsDeliveryCostOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('delivery cost can be recorded through the Assign & Notify Rider action', function () {
    $data = setUpDeliveryCostVendor();
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => null, 'delivery_person_id' => null, 'delivery_cost' => 1200])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect((float) $data['order']->fresh()->delivery_cost)->toBe(1200.0);
});

test('the logistics card shows "Not recorded yet" until a delivery cost is set', function () {
    $data = setUpDeliveryCostVendor();
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('Not recorded yet');

    $data['order']->update(['delivery_cost' => 1500]);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('1,500.00');
});

test('recording a delivery payment posts an out ledger entry from the chosen account', function () {
    $data = setUpDeliveryCostVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $data['order']->update(['delivery_cost' => 2000]);
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->callAction('recordDeliveryPayment', data: ['financial_account_id' => $account->id])
        ->assertHasNoActionErrors();

    $order = $data['order']->fresh();

    expect($order->isDeliveryCostPosted())->toBeTrue()
        ->and($order->financial_account_id)->toBe($account->id)
        ->and($account->fresh()->balance())->toBe(-2000.0)
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->count())->toBe(1);
});

test('the Record Delivery Payment action is hidden until a delivery cost is set', function () {
    $data = setUpDeliveryCostVendor();
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertActionHidden('recordDeliveryPayment');
});

test('the Record Delivery Payment action is hidden once already posted', function () {
    $data = setUpDeliveryCostVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $data['order']->update(['delivery_cost' => 2000]);
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertActionVisible('recordDeliveryPayment')
        ->callAction('recordDeliveryPayment', data: ['financial_account_id' => $account->id])
        ->assertActionHidden('recordDeliveryPayment');
});

test('a storekeeper cannot see the Record Delivery Payment action', function () {
    $data = setUpDeliveryCostVendor();
    $data['order']->update(['delivery_cost' => 2000]);

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    test()->actingAs($storekeeper);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertActionHidden('recordDeliveryPayment');
});

test('a posted delivery cost cannot be changed directly on the model', function () {
    $data = setUpDeliveryCostVendor();
    $data['order']->update(['delivery_cost' => 2000, 'financial_account_id' => FinancialAccount::where('vendor_id', $data['vendor']->id)->first()->id, 'posted_at' => now()]);

    expect(fn () => $data['order']->update(['delivery_cost' => 9999]))
        ->toThrow(\LogicException::class);
});

test('the delivery cost field is disabled in the assignment form once posted', function () {
    $data = setUpDeliveryCostVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $data['order']->update(['delivery_cost' => 2000, 'financial_account_id' => $account->id, 'posted_at' => now()]);
    actAsDeliveryCostOwner($data);

    // Attempting to change it via the action must not silently succeed —
    // the field is disabled client-side, and the action itself skips
    // delivery_cost from $data entirely once posted, as a second line of
    // defense alongside the model's own guard.
    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData(['logistics_company_id' => null, 'delivery_person_id' => null, 'delivery_cost' => 9999])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect((float) $data['order']->fresh()->delivery_cost)->toBe(2000.0);
});
