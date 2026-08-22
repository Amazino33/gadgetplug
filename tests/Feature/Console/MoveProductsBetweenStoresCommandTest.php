<?php

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A product's store is decided by whichever store was active when it was
// created, never by its brand — so importing a brand-specific file while the
// wrong store is active puts everything in the wrong place. This command is
// the after-the-fact fix.

function moveStoreContext(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Zelink Tech']);

    $storeA = Store::create(['vendor_id' => $vendor->id, 'name' => 'Itel Home', 'is_default' => true]);
    $storeB = Store::create(['vendor_id' => $vendor->id, 'name' => 'Oraimo Store']);

    $category = Category::firstOrCreate(['name' => 'Cables'], ['slug' => 'move-test-cables']);

    return compact('vendor', 'storeA', 'storeB', 'category');
}

it('moves matching products to the destination store', function () {
    $c = moveStoreContext();

    $oraimo = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'OCD-114MJ', 'brand' => 'Oraimo', 'price' => 990, 'store_id' => $c['storeA']->id,
    ]);

    $itel = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'ITEL P65C', 'brand' => 'Itel', 'price' => 50000, 'store_id' => $c['storeA']->id,
    ]);

    $this->artisan('products:move-store', [
        'vendor' => $c['vendor']->id,
        'from'   => $c['storeA']->id,
        'to'     => $c['storeB']->id,
        '--brand' => 'Oraimo',
        '--force' => true,
    ])->assertSuccessful();

    expect($oraimo->fresh()->store_id)->toBe($c['storeB']->id)
        // The Itel product, same source store, no brand match — left alone.
        ->and($itel->fresh()->store_id)->toBe($c['storeA']->id);
});

it('moves the product\'s stock row along with it, not just the label', function () {
    $c = moveStoreContext();

    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'OCD-114MJ', 'brand' => 'Oraimo', 'price' => 990,
        'store_id' => $c['storeA']->id, 'stock_quantity' => 40,
    ]);

    $this->artisan('products:move-store', [
        'vendor' => $c['vendor']->id, 'from' => $c['storeA']->id, 'to' => $c['storeB']->id, '--force' => true,
    ]);

    expect($product->storeStocks()->where('store_id', $c['storeB']->id)->value('quantity'))->toBe(40)
        ->and($product->storeStocks()->where('store_id', $c['storeA']->id)->exists())->toBeFalse();
});

it('does nothing without --force, and says so', function () {
    $c = moveStoreContext();

    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'OCD-114MJ', 'brand' => 'Oraimo', 'price' => 990, 'store_id' => $c['storeA']->id,
    ]);

    $this->artisan('products:move-store', [
        'vendor' => $c['vendor']->id, 'from' => $c['storeA']->id, 'to' => $c['storeB']->id,
    ])->expectsOutputToContain('Dry run');

    expect($product->fresh()->store_id)->toBe($c['storeA']->id);
});

it('skips a product with reserved stock and reports why, without failing the rest', function () {
    $c = moveStoreContext();

    $free = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'Free product', 'brand' => 'Oraimo', 'price' => 990,
        'store_id' => $c['storeA']->id, 'stock_quantity' => 10,
    ]);

    $reserved = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'Reserved product', 'brand' => 'Oraimo', 'price' => 990,
        'store_id' => $c['storeA']->id, 'stock_quantity' => 10,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $reserved->id,
        quantity: 3,
        reference: 'GP-TEST',
        description: 'A live order holding stock at the source store',
    );

    $this->artisan('products:move-store', [
        'vendor' => $c['vendor']->id, 'from' => $c['storeA']->id, 'to' => $c['storeB']->id,
        '--brand' => 'Oraimo', '--force' => true,
    ])
        ->expectsOutputToContain('1 product(s) moved')
        ->expectsOutputToContain('could not be moved')
        ->assertSuccessful();

    expect($free->fresh()->store_id)->toBe($c['storeB']->id)
        // Still at the source — 3 units are spoken for by a real order there.
        ->and($reserved->fresh()->store_id)->toBe($c['storeA']->id);
});

it('never touches another vendor\'s products even with the same store ids', function () {
    $c      = moveStoreContext();
    $other  = moveStoreContext();

    $mine = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'Mine', 'brand' => 'Oraimo', 'price' => 990, 'store_id' => $c['storeA']->id,
    ]);

    $theirs = Product::create([
        'vendor_id' => $other['vendor']->id, 'category_id' => $other['category']->id,
        'name' => 'Theirs', 'brand' => 'Oraimo', 'price' => 990, 'store_id' => $other['storeA']->id,
    ]);

    $this->artisan('products:move-store', [
        'vendor' => $c['vendor']->id, 'from' => $c['storeA']->id, 'to' => $c['storeB']->id, '--force' => true,
    ]);

    expect($mine->fresh()->store_id)->toBe($c['storeB']->id)
        ->and($theirs->fresh()->store_id)->toBe($other['storeA']->id);
});
