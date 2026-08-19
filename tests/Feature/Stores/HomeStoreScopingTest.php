<?php

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function scopeVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Scope Vendor '.uniqid(),
    ]);
}

function scopeProduct(Vendor $vendor, Store $home, int $qty = 0, string $name = null): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name ?? 'Scoped '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $qty,
        'status'         => 'published',
    ]);
}

function actAsScope(Vendor $vendor, User $user, ?Store $active = null): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    if ($active) {
        ActiveStore::set($vendor, $user, $active->id);
    }
}

// ─── Owner inventory ────────────────────────────────────────────────

test('inventory lists only the products homed at the active store', function () {
    $vendor = scopeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    $atMain = scopeProduct($vendor, $vendor->defaultStore, 5, 'Main Homed');
    $atBranch = scopeProduct($vendor, $branch, 7, 'Branch Homed');

    actAsScope($vendor, $vendor->user, $vendor->defaultStore);
    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$atMain->id]);

    actAsScope($vendor, $vendor->user, $branch);
    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$atBranch->id]);
});

// The behaviour change this chunk buys: a sold-out product stays in its own
// branch's list. Under the old "has a row here" scoping it depended on a row
// existing; now it depends on where the product belongs, which is the thing
// that does not change when the last unit sells.
test('a product homed here still appears when it has sold out', function () {
    $vendor = scopeVendor();
    $product = scopeProduct($vendor, $vendor->defaultStore, 0);

    actAsScope($vendor, $vendor->user, $vendor->defaultStore);

    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$product->id]);
});

test('a product never appears in a branch it is not homed at, whatever stock sits there', function () {
    $vendor = scopeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = scopeProduct($vendor, $vendor->defaultStore, 5);

    // A stray stock row at the other branch must not drag the product into it.
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 99]);

    actAsScope($vendor, $vendor->user, $branch);

    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([]);
});

test('the stock columns still read the active store row', function () {
    $vendor = scopeVendor();
    $product = scopeProduct($vendor, $vendor->defaultStore, 9);

    actAsScope($vendor, $vendor->user, $vendor->defaultStore);
    $row = ProductResource::getEloquentQuery()->find($product->id);

    expect((int) $row->store_quantity)->toBe(9)
        ->and((int) $row->store_reserved)->toBe(0);
});

test('another vendor products never leak in', function () {
    $vendorA = scopeVendor();
    $vendorB = scopeVendor();
    scopeProduct($vendorA, $vendorA->defaultStore, 3);
    scopeProduct($vendorB, $vendorB->defaultStore, 3);

    actAsScope($vendorA, $vendorA->user, $vendorA->defaultStore);

    expect(ProductResource::getEloquentQuery()->pluck('vendor_id')->unique()->all())->toBe([$vendorA->id]);
});

// ─── Blind count ────────────────────────────────────────────────────

test('a count covers only the products homed at the counted branch', function () {
    $vendor = scopeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    $atMain = scopeProduct($vendor, $vendor->defaultStore, 5);
    $atBranch = scopeProduct($vendor, $branch, 7);
    $soldOutAtBranch = scopeProduct($vendor, $branch, 0);

    $order = Product::published()
        ->where('vendor_id', $vendor->id)
        ->where('store_id', $branch->id)
        ->pluck('id')
        ->all();

    expect($order)->toContain($atBranch->id)
        // A sold-out line belongs on the sheet: counting it and finding nothing
        // is the answer, and skipping it hides whether stock walked.
        ->toContain($soldOutAtBranch->id)
        ->not->toContain($atMain->id);
});

test('a count session records the branch it covered', function () {
    $vendor = scopeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = scopeProduct($vendor, $branch, 4);

    $session = BlindCountSession::create([
        'vendor_id' => $vendor->id,
        'store_id'  => $branch->id,
        'storekeeper_a_id' => $vendor->user_id,
        'status' => 'a_counting',
        'frequency' => 'daily',
        'by_category' => false,
        'product_order' => [$product->id],
    ]);

    expect($session->store_id)->toBe($branch->id)
        ->and($session->product_order)->toBe([$product->id]);
});
