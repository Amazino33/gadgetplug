<?php

use App\Filament\Vendor\Pages\RestockReport;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function setUpRestockStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Restock Report Store']);
    VendorRoles::seedFor($vendor);

    $category = Category::firstOrCreate(['name' => 'Restock Report Category']);

    // Long-established, not "new" — created_at must predate the trailing
    // window or every product here reads as Watch regardless of sales.
    $establishedAt = now()->subYear();

    // Urgent: velocity = 1/day (30 units over 30 days), stock = 3 → days_of_cover = 3 ≤ lead time (5).
    $urgentProduct = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Urgent Widget', 'sku' => 'URG-' . Str::random(6),
        'price' => 1000, 'cost_price' => 400, 'stock_quantity' => 3, 'status' => 'published',
        'created_at' => $establishedAt,
    ]);
    deliverRestockOrder($vendor, $urgentProduct, 30);

    // Healthy: same velocity, plenty of stock → hidden by default.
    $healthyProduct = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Healthy Widget', 'sku' => 'HLT-' . Str::random(6),
        'price' => 1000, 'cost_price' => 400, 'stock_quantity' => 100, 'status' => 'published',
        'created_at' => $establishedAt,
    ]);
    deliverRestockOrder($vendor, $healthyProduct, 30);

    return compact('owner', 'vendor', 'category', 'urgentProduct', 'healthyProduct');
}

function deliverRestockOrder(Vendor $vendor, Product $product, int $quantity): void
{
    $order = Order::create([
        'reference' => 'ORD-RR-' . Str::random(8),
        'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => $product->price * $quantity,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery',
        'revenue_recognized_at' => now()->subDays(10),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);
}

it('a storekeeper cannot access the restock report page', function () {
    $data = setUpRestockStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

it('a role explicitly granted view_restock_report can access the page', function () {
    $data = setUpRestockStore();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('view_restock_report');

    $this->actingAs($manager)
        ->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

it('shows a product that needs restocking and hides a healthy one by default', function () {
    $data = setUpRestockStore();

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('Urgent Widget')
        ->assertSee('Urgent')
        ->assertDontSee('Healthy Widget');
});

it('shows healthy and dead stock when the Show All toggle is on', function () {
    $data = setUpRestockStore();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(RestockReport::class)
        ->set('filters.showAll', true)
        ->assertSee('Urgent Widget')
        ->assertSee('Healthy Widget');
});

it('the reorder quantity and estimated cost are correct for the urgent product', function () {
    $data = setUpRestockStore();

    // velocity = 1/day, target cover 30 days, stock 3 → ceil(30*1 - 3) = 27
    // cost_price 400 → estimated cost = 27 * 400 = 10,800.00
    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('27')
        ->assertSee('10,800.00');
});

it('search filters the table by product name', function () {
    $data = setUpRestockStore();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(RestockReport::class)
        ->set('filters.showAll', true)
        ->set('filters.search', 'Healthy')
        ->assertSee('Healthy Widget')
        ->assertDontSee('Urgent Widget');
});

it('the category filter scopes the table to one category', function () {
    $data = setUpRestockStore();
    $otherCategory = Category::create(['name' => 'Other Restock Category']);
    $otherProduct = Product::create([
        'vendor_id' => $data['vendor']->id, 'category_id' => $otherCategory->id,
        'name' => 'Other Category Widget', 'stock_quantity' => 1, 'status' => 'published', 'price' => 500,
    ]);
    deliverRestockOrder($data['vendor'], $otherProduct, 30);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(RestockReport::class)
        ->set('filters.category_id', $data['category']->id)
        ->assertSee('Urgent Widget')
        ->assertDontSee('Other Category Widget');
});

it('the owner can change restock settings, which changes the resulting classification', function () {
    $data = setUpRestockStore();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    // Urgent Widget has 3 days of cover — raising lead time to 2 days makes
    // it merely "Reorder now" (3 > 2) instead of "Urgent" (3 <= 5 before).
    Livewire::test(RestockReport::class)
        ->callAction('restockSettings', data: [
            'restock_window_days' => 30,
            'restock_lead_time_days' => 2,
            'restock_target_cover_days' => 30,
            'restock_safety_buffer_days' => 5,
        ])
        ->assertHasNoActionErrors();

    expect($data['vendor']->fresh()->restock_lead_time_days)->toBe(2);

    $this->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertSee('Reorder now');
});

it('a storekeeper cannot reach the page at all, so cannot change restock settings either', function () {
    $data = setUpRestockStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.restock-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));

    expect($data['vendor']->fresh()->restock_lead_time_days)->toBeNull();
});
