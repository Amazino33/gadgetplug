<?php

use App\Filament\Vendor\Pages\SalesReport;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpSalesReportVendor(): array
{
    $owner    = User::factory()->create(['name' => 'Report Owner']);
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Sales Report Page Store']);
    $category = Category::create(['name' => 'Sales Report Page Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Sales Report Page Product',
        'price'          => 2000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function actAsSalesReportVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('the sales report page shows the cashier breakdown table', function () {
    $data = setUpSalesReportVendor();

    PosSale::create([
        'reference'       => 'POS-REPORTPAGE1',
        'vendor_id'       => $data['vendor']->id,
        'cashier_id'      => $data['owner']->id,
        'subtotal'        => 2000,
        'vat_amount'      => 0,
        'total'           => 2000,
        'payment_method'  => 'cash',
        'status'          => 'completed',
        'completed_at'    => CarbonImmutable::now(),
    ]);

    actAsSalesReportVendor($data);

    Livewire::test(SalesReport::class)
        ->assertSee('Sales by team member')
        ->assertSee('Report Owner');
});

test('the sales report page explains why online revenue is zero when orders exist but are unpaid', function () {
    $data = setUpSalesReportVendor();

    Order::create([
        'reference'        => 'GP-REPORTPAGE1',
        'customer_name'    => 'Test Buyer',
        'customer_email'   => 'buyer@example.com',
        'customer_phone'   => '08010000000',
        'shipping_address' => 'Uyo',
        'total_amount'     => 2000,
        'status'           => 'confirmed',
        'payment_method'   => 'pay_on_delivery',
    ]);

    $order = Order::first();

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $data['product']->id,
        'vendor_id'  => $data['vendor']->id,
        'quantity'   => 1,
        'unit_price' => 2000,
    ]);

    actAsSalesReportVendor($data);

    Livewire::test(SalesReport::class)
        ->assertSee('not yet counted as revenue')
        ->assertSee('1 confirmed');
});
