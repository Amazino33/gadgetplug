<?php

use App\Filament\Vendor\Pages\FinancialReport;
use App\Models\Category;
use App\Models\FinancialAccount;
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

function setUpFinancialReportStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Financial Report Store']);
    VendorRoles::seedFor($vendor);

    $category = Category::firstOrCreate(['name' => 'Financial Report Category']);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Report Widget', 'sku' => 'FRW-' . Str::random(6),
        'price' => 5000, 'cost_price' => 3000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-FR-' . Str::random(8),
        'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 5000, 'status' => 'delivered', 'payment_method' => 'pay_on_delivery',
        'revenue_recognized_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 5000, 'unit_cost' => 3000,
    ]);

    return compact('owner', 'vendor', 'category', 'product', 'order');
}

it('a storekeeper cannot access the financial report page', function () {
    $data = setUpFinancialReportStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

it('a role explicitly granted manage_financial_reports can access the page', function () {
    $data = setUpFinancialReportStore();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('manage_financial_reports');

    $this->actingAs($manager)
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

it('renders the financial report page with net profit and the balances block', function () {
    $data = setUpFinancialReportStore();

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('Net Profit')
        // 5000 revenue - 3000 product cost = 2000 net profit
        ->assertSee('2,000.00')
        ->assertSee('Balances')
        ->assertSee('Total worth', false);
});

it('shows the approximate-cost warning only when a sold line has no recorded unit_cost', function () {
    $data = setUpFinancialReportStore();
    $data['order']->items()->update(['unit_cost' => null]);

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('partly approximate');
});

it('does not show the approximate-cost warning when every sold line has a recorded unit_cost', function () {
    $data = setUpFinancialReportStore();

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertDontSee('partly approximate');
});

it('shows the bank and cash balances', function () {
    $data = setUpFinancialReportStore();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    $bank->update(['opening_balance' => 15000]);

    $this->actingAs($data['owner'])
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee('15,000.00');
});

it('the owner can set the initial capital figure, and it appears in the balances block', function () {
    $data = setUpFinancialReportStore();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(FinancialReport::class)
        ->callAction('setInitialCapital', data: ['initial_capital' => 300000])
        ->assertHasNoActionErrors();

    expect((float) $data['vendor']->fresh()->initial_capital)->toBe(300000.0);

    $this->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertSee('300,000.00');
});

it('a storekeeper cannot reach the page at all, so cannot set initial capital either', function () {
    $data = setUpFinancialReportStore();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.pages.financial-report', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));

    expect($data['vendor']->fresh()->initial_capital)->toBeNull();
});
