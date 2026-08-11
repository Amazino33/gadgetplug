<?php

use App\Filament\Vendor\Pages\TopSellersReport;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function setUpTopSellersStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Top Sellers Page Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Top Sellers Page Category']);

    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Fastest Widget', 'price' => 1500, 'cost_price' => 600,
        'stock_quantity' => 20, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-TSP-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $product->price * 12,
        // Default period is "Today" — must land inside it or every rendering
        // assertion below sees "Nothing sold in this period yet" instead.
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 12, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

it('a storekeeper cannot access the top sellers report', function () {
    $data = setUpTopSellersStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.top-sellers-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

it('a role explicitly granted view_inventory_reports can access the page', function () {
    $data = setUpTopSellersStore();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('view_inventory_reports');

    $this->actingAs($manager)
        ->get(route('filament.vendor.pages.top-sellers-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

it('shows the seeded product ranked with its units sold and revenue', function () {
    $data = setUpTopSellersStore();

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.top-sellers-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('Fastest Widget')
        ->assertSee('12') // units sold
        ->assertSee('18,000.00'); // revenue: 12 * 1500
});

it('the category filter narrows the ranking', function () {
    $data = setUpTopSellersStore();
    $otherCategory = Category::create(['name' => 'Other Top Sellers Category']);
    $otherProduct = Product::create([
        'vendor_id' => $data['vendor']->id, 'category_id' => $otherCategory->id,
        'name' => 'Other Category Seller', 'price' => 500, 'stock_quantity' => 5, 'status' => 'published',
    ]);
    $order = Order::create([
        'reference' => 'ORD-TSP-OTHER', 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => 500,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $otherProduct->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => 1, 'unit_price' => 500,
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(TopSellersReport::class)
        ->set('filters.category_id', $data['category']->id)
        ->assertSee('Fastest Widget')
        ->assertDontSee('Other Category Seller');
});

it('a storekeeper cannot reach the page at all', function () {
    $data = setUpTopSellersStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.top-sellers-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});
