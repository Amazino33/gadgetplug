<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

// Online orders are delivered by GadgetPlug's own logistics companies, assigned
// from the admin dispatch board. The vendor sees who is delivering; it no
// longer assigns its own riders, and its own companies are never offered.

function setUpOrderLogisticsVendor(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Order Logistics Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Order Logistics Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Order Logistics Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'ORD-LOG-' . uniqid(),
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 5000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 5000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsOrderVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('the order view page shows "not assigned yet" when no logistics is set', function () {
    $data = setUpOrderLogisticsVendor();
    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('Not assigned yet');
});

test('the vendor order page has no assignment action', function () {
    $data = setUpOrderLogisticsVendor();
    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertActionDoesNotExist('assignLogistics');
});

test('the vendor sees the GadgetPlug company and rider once dispatch assigns them', function () {
    $data = setUpOrderLogisticsVendor();
    $company = LogisticsCompany::create(['vendor_id' => null, 'name' => 'Swift Riders Uyo', 'phone' => '08010000000']);
    $data['order']->updateQuietly(['logistics_company_id' => $company->id, 'rider_name' => 'Emeka', 'rider_phone' => '08020000000']);
    actAsOrderVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->assertSeeText('Swift Riders Uyo')
        ->assertSeeText('Emeka')
        // GadgetPlug's side (what it pays the company) is not the vendor's to see.
        ->assertDontSeeText('What we pay the company');
});

test("a vendor's own logistics companies are never offered for online orders", function () {
    $data = setUpOrderLogisticsVendor();
    LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Vendor Own Riders', 'phone' => '08010000001']);
    $platform = LogisticsCompany::create(['vendor_id' => null, 'name' => 'GadgetPlug Partner', 'phone' => '08010000002']);

    expect(app(App\Services\Orders\DispatchService::class)->companyOptionsFor($data['order'])->keys()->all())
        ->toBe([$platform->id]);
});
