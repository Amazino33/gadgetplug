<?php

use App\Filament\Resources\Vendors\VendorResource;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CartService;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function gatingVendorProduct(bool $onlineSalesEnabled): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'               => $owner->id,
        'name'                  => 'Gating Test Store ' . uniqid(),
        'online_sales_enabled'  => $onlineSalesEnabled,
    ]);
    $category = Category::create(['name' => 'Gating Test Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Gated Widget ' . uniqid(),
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
        'show_online'    => true,
        'show_in_pos'    => true,
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function actAsGatingOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

// ─── Storefront ─────────────────────────────────────────────────────

test('a disabled vendor\'s products are absent from the storefront listing', function () {
    $enabled = gatingVendorProduct(true);
    $disabled = gatingVendorProduct(false);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain($enabled['product']->name)
        ->and($html)->not->toContain($disabled['product']->name);
});

test('a disabled vendor\'s product page 404s for a public visitor', function () {
    $data = gatingVendorProduct(false);

    $this->get(route('product.show', $data['product']))->assertNotFound();
});

test('an enabled vendor\'s product page still renders normally — regression guard', function () {
    $data = gatingVendorProduct(true);

    $this->get(route('product.show', $data['product']))->assertOk();
});

// ─── Cart / checkout ────────────────────────────────────────────────

test('a disabled vendor\'s product cannot be added to the cart', function () {
    $data = gatingVendorProduct(false);

    expect(app(CartService::class)->add($data['product']))->toBeFalse();
});

test('an enabled vendor\'s product can still be added to the cart — regression guard', function () {
    $data = gatingVendorProduct(true);

    expect(app(CartService::class)->add($data['product']))->toBeTrue();
});

test('checkout drops a disabled vendor\'s item on load, before InitiateCheckout would fire, leaving the rest of the cart intact', function () {
    $enabled = gatingVendorProduct(true);
    $disabled = gatingVendorProduct(false);

    Session::put('cart', [
        $enabled['product']->id  => ['quantity' => 1, 'max' => 10],
        $disabled['product']->id => ['quantity' => 1, 'max' => 10],
    ]);

    $component = Volt::test('checkout');

    expect($component->get('cartItems'))->toHaveCount(1)
        ->and($component->get('cartItems')[0]['product']->id)->toBe($enabled['product']->id)
        ->and($component->get('total'))->toBe(5000.0);
});

test('an order is never created for a disabled vendor, even from a stale session cart bypassing CartService', function () {
    $data = gatingVendorProduct(false);

    // Simulates a cart populated before the vendor was disabled — the exact
    // race the checkout-time guard exists to catch, since CartService::add()
    // itself already refuses this on the normal add-to-cart path.
    Session::put('cart', [$data['product']->id => ['quantity' => 1, 'max' => 10]]);

    Volt::test('checkout')->assertRedirect(route('cart'));

    expect(Order::count())->toBe(0);
});

// ─── Vendor panel ───────────────────────────────────────────────────

test('a disabled vendor owner cannot reach the Orders resource by direct URL', function () {
    $data = gatingVendorProduct(false);
    actAsGatingOwner($data);

    $this->get(route('filament.vendor.resources.orders.index', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

test('an enabled vendor owner can reach the Orders resource — regression guard', function () {
    $data = gatingVendorProduct(true);
    actAsGatingOwner($data);

    $this->get(route('filament.vendor.resources.orders.index', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

test('super admin can still reach a disabled vendor\'s Orders resource', function () {
    $data = gatingVendorProduct(false);

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    $this->get(route('filament.vendor.resources.orders.index', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

test('a disabled vendor does not see the Orders link in their panel navigation', function () {
    $data = gatingVendorProduct(false);
    actAsGatingOwner($data);

    $ordersUrl = route('filament.vendor.resources.orders.index', ['tenant' => $data['vendor']->slug]);

    $this->followingRedirects()
        ->get(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertDontSee($ordersUrl, false);
});

test('an enabled vendor sees the Orders link in their panel navigation — regression guard', function () {
    $data = gatingVendorProduct(true);
    actAsGatingOwner($data);

    $ordersUrl = route('filament.vendor.resources.orders.index', ['tenant' => $data['vendor']->slug]);

    $this->followingRedirects()
        ->get(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]))
        ->assertOk()
        ->assertSee($ordersUrl, false);
});

// ─── Admin control ──────────────────────────────────────────────────

test('a super admin can toggle online sales for a vendor, and the change is logged', function () {
    $data = gatingVendorProduct(true);

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($admin);

    $data['vendor']->update(['online_sales_enabled' => false]);

    expect($data['vendor']->fresh()->online_sales_enabled)->toBeFalse();

    $activity = Activity::where('subject_type', Vendor::class)
        ->where('subject_id', $data['vendor']->id)
        ->latest()
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('Online sales disabled for vendor')
        ->and($activity->causer_id)->toBe($admin->id);
});

test('a non-super-admin cannot access the admin vendor resource at all', function () {
    $data = gatingVendorProduct(true);
    $this->actingAs($data['owner']);

    expect(VendorResource::canAccess())->toBeFalse();
});

// ─── POS / offline — must be unaffected ────────────────────────────

test('visibleInPos still includes a disabled vendor\'s product — POS is unaffected by online-sales gating', function () {
    $data = gatingVendorProduct(false);

    $visibleIds = Product::visibleInPos()->pluck('id');

    expect($visibleIds)->toContain($data['product']->id);
});
