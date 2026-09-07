<?php

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A bulk stock adjustment writes every line to whichever store was active in
// the panel when it was applied. One wrong selection in the switcher sent a
// whole vendor sheet — 10 products, 115 units — to a branch that has no
// products homed to it at all, while the goods themselves sat on a different
// shelf. This puts the units where they actually are, without deleting the
// record of how they got misfiled.

function transferContext(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Zeelink Tech']);
    $phones   = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones', 'is_default' => true]);
    $accs     = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Accesories']);
    $category = Category::create(['name' => 'Phones '.uniqid()]);

    return compact('owner', 'vendor', 'phones', 'accs', 'category');
}

/** A product homed at Phones, whose stock was mistakenly applied to Accesories. */
function misfiledProduct(array $c, string $name, int $atPhones, int $atAccessories): Product
{
    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => $name, 'price' => 50000, 'store_id' => $c['phones']->id,
        'stock_quantity' => $atPhones, 'status' => 'published',
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $c['accs']->id],
        ['quantity' => $atAccessories, 'reserved' => 0],
    );

    return $product->fresh();
}

it('moves every unit sitting in the wrong branch onto the right one', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'T102 TECNO', atPhones: 2, atAccessories: 7);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id,
        'from'   => $c['accs']->id,
        '--to'   => $c['phones']->id,
        '--force' => true,
    ])->assertSuccessful();

    $atPhones = ProductStoreStock::where('product_id', $product->id)->where('store_id', $c['phones']->id)->first();
    $atAccs   = ProductStoreStock::where('product_id', $product->id)->where('store_id', $c['accs']->id)->first();

    expect($atPhones->quantity)->toBe(9)
        ->and($atAccs->quantity)->toBe(0);
});

it('leaves the vendor-wide total exactly where it was — nothing is created or lost', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'T403 TECNO', atPhones: 2, atAccessories: 28);

    $before = (int) $product->fresh()->stock_quantity;

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id,
        '--to' => $c['phones']->id, '--force' => true,
    ]);

    expect((int) $product->fresh()->stock_quantity)->toBe($before);
});

it('records the correction on both shelves rather than quietly rewriting them', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'TECNO 101', atPhones: 0, atAccessories: 22);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id,
        '--to' => $c['phones']->id, '--force' => true,
    ]);

    $entries = InventoryLedger::where('product_id', $product->id)
        ->where('transaction_type', 'store_transfer')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->firstWhere('store_id', $c['accs']->id)->quantity_change)->toBe(-22)
        ->and($entries->firstWhere('store_id', $c['phones']->id)->quantity_change)->toBe(22);
});

it('does nothing without --force, and says so', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'POP 20 64+4', atPhones: 1, atAccessories: 13);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id, '--to' => $c['phones']->id,
    ])->expectsOutputToContain('Dry run');

    $atAccs = ProductStoreStock::where('product_id', $product->id)->where('store_id', $c['accs']->id)->first();

    expect($atAccs->quantity)->toBe(13);
});

it('skips a product whose units at the source are reserved for a live order', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'REDMI A7 PRO', atPhones: 0, atAccessories: 12);

    ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $c['accs']->id)
        ->update(['reserved' => 3]);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id,
        '--to' => $c['phones']->id, '--force' => true,
    ])->expectsOutputToContain('skipped');

    // Left exactly as it was — an order is holding those units at this branch.
    $atAccs = ProductStoreStock::where('product_id', $product->id)->where('store_id', $c['accs']->id)->first();

    expect($atAccs->quantity)->toBe(12);
});

it('writes the stock off the source when no destination is given', function () {
    $c = transferContext();
    $product = misfiledProduct($c, 'T530', atPhones: 4, atAccessories: 9);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id, '--force' => true,
    ]);

    $atAccs = ProductStoreStock::where('product_id', $product->id)->where('store_id', $c['accs']->id)->first();

    expect($atAccs->quantity)->toBe(0)
        // Written off, not moved: the vendor total drops by exactly what left.
        ->and((int) $product->fresh()->stock_quantity)->toBe(4);
});

it('can be pointed at a single product when only one line was wrong', function () {
    $c = transferContext();
    $wrong = misfiledProduct($c, 'HOT 70 128+4', atPhones: 0, atAccessories: 7);
    $other = misfiledProduct($c, '15C 128+4 REDIMI', atPhones: 0, atAccessories: 1);

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id,
        '--to' => $c['phones']->id, '--product' => $wrong->id, '--force' => true,
    ]);

    expect(ProductStoreStock::where('product_id', $wrong->id)->where('store_id', $c['accs']->id)->first()->quantity)->toBe(0)
        ->and(ProductStoreStock::where('product_id', $other->id)->where('store_id', $c['accs']->id)->first()->quantity)->toBe(1);
});

it('never touches another vendor stock sitting in their own store', function () {
    $mine   = transferContext();
    $theirs = transferContext();

    $myProduct    = misfiledProduct($mine, 'Mine', atPhones: 0, atAccessories: 5);
    $theirProduct = misfiledProduct($theirs, 'Theirs', atPhones: 0, atAccessories: 5);

    $this->artisan('stock:transfer', [
        'vendor' => $mine['vendor']->id, 'from' => $mine['accs']->id,
        '--to' => $mine['phones']->id, '--force' => true,
    ]);

    expect(ProductStoreStock::where('product_id', $myProduct->id)->where('store_id', $mine['accs']->id)->first()->quantity)->toBe(0)
        ->and(ProductStoreStock::where('product_id', $theirProduct->id)->where('store_id', $theirs['accs']->id)->first()->quantity)->toBe(5);
});

it('refuses a store that belongs to a different vendor', function () {
    $mine   = transferContext();
    $theirs = transferContext();

    $this->artisan('stock:transfer', [
        'vendor' => $mine['vendor']->id,
        'from'   => $theirs['accs']->id,
        '--to'   => $mine['phones']->id,
        '--force' => true,
    ])->assertFailed();
});

it('refuses to move stock into the store it is already in', function () {
    $c = transferContext();

    $this->artisan('stock:transfer', [
        'vendor' => $c['vendor']->id, 'from' => $c['accs']->id,
        '--to' => $c['accs']->id, '--force' => true,
    ])->assertFailed();
});
