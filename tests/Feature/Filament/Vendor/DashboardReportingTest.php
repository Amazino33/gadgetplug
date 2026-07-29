<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Support\Str;

function setUpDashboardStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Dashboard Test Store']);
    VendorRoles::seedFor($vendor);

    $category = Category::firstOrCreate(['name' => 'Dashboard Test Category']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Dashboard Widget',
        'sku' => 'DSH-'.Str::random(6),
        'price' => 2500,
        'cost_price' => 1000,
        'stock_quantity' => 20,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $sale = PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $vendor->id,
        'cashier_id' => $owner->id,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'vat_amount' => 375,
        'total' => 5375,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 2500,
        'unit_cost' => 1000,
        'quantity' => 2,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    return compact('owner', 'vendor', 'product');
}

it('renders the dashboard with the period filter', function () {
    $ctx = setUpDashboardStore();

    $this->actingAs($ctx['owner'])
        ->get(route('filament.vendor.pages.dashboard', ['tenant' => $ctx['vendor']->slug]))
        ->assertOk();
});

it('renders the sales report page', function () {
    $ctx = setUpDashboardStore();

    $this->actingAs($ctx['owner'])
        ->get(route('filament.vendor.pages.sales-report', ['tenant' => $ctx['vendor']->slug]))
        ->assertOk()
        // The POS sale's revenue, net of VAT, must actually appear on the page
        ->assertSee('5,000.00')
        // ...and its profit: 5000 revenue - 2000 cost
        ->assertSee('3,000.00');
});
