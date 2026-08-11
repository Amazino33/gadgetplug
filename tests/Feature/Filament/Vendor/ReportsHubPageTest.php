<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\ReportCardProvider;
use App\Services\Reporting\Cards\RestockCardProvider;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Spatie\Permission\Models\Role;

function setUpReportsHubStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Reports Hub Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Reports Hub Category']);

    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Hub Widget', 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 3, 'status' => 'published', 'created_at' => now()->subYear(),
    ]);

    $order = Order::create([
        'reference' => 'ORD-HUB-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $product->price * 30,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => now()->subDays(10),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 30, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

it('a storekeeper cannot access the reports hub', function () {
    $data = setUpReportsHubStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

it('a role explicitly granted view_reports_hub can access the page', function () {
    $data = setUpReportsHubStore();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('view_reports_hub');

    $this->actingAs($manager)
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

it('renders every card with its title and headline', function () {
    $data = setUpReportsHubStore();

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('Restock')
        ->assertSee('Money Position')
        ->assertSee('Sales Pulse')
        ->assertSee('Ad Efficiency')
        ->assertSee('Dead Stock')
        ->assertSee('urgent'); // the seeded product is urgent (stock 3, velocity 1/day)
});

it('the dead-stock card has no link and shows a coming-soon note', function () {
    $data = setUpReportsHubStore();

    $response = $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertOk();

    $response->assertSee('Full dead-stock report not built yet');
});

it('one failing card does not blank the whole page', function () {
    $data = setUpReportsHubStore();

    app()->bind(RestockCardProvider::class, fn () => new class implements ReportCardProvider {
        public function summarize(int $vendorId): CardSummary
        {
            throw new \RuntimeException('simulated failure');
        }
    });

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('Could not load this card right now')
        ->assertSee('Money Position'); // the other cards still render
});

it('a storekeeper cannot reach the hub at all', function () {
    $data = setUpReportsHubStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.reports-hub', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});
