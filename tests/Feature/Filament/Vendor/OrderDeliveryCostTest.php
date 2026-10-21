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

// GadgetPlug pays the logistics company for an online order (admin dispatch:
// company_cost and "paid to company"), so the vendor panel no longer records
// a delivery cost or posts it from the vendor's accounts. What a vendor
// recorded before that still shows on the order, and stays locked once posted.

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

test('the vendor order page no longer assigns riders or pays a delivery cost', function () {
    $data = setUpDeliveryCostVendor();
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertActionDoesNotExist('assignLogistics')
        ->assertActionDoesNotExist('recordDeliveryPayment');
});

test('a delivery cost the vendor recorded before still shows on the order', function () {
    $data = setUpDeliveryCostVendor();
    actAsDeliveryCostOwner($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertDontSeeText('What You Pay to Deliver');

    $data['order']->update(['delivery_cost' => 1500]);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('What You Pay to Deliver')
        ->assertSeeText('1,500.00');
});

test('a posted delivery cost cannot be changed directly on the model', function () {
    $data = setUpDeliveryCostVendor();
    $data['order']->update(['delivery_cost' => 2000, 'financial_account_id' => FinancialAccount::where('vendor_id', $data['vendor']->id)->first()->id, 'posted_at' => now()]);

    expect(fn () => $data['order']->update(['delivery_cost' => 9999]))
        ->toThrow(\LogicException::class);
});
