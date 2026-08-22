<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Reproduces the exact failure: a real product created by one import, then
// silently renamed and repriced by a second import whose row happened to share
// the same SKU. The overwrite is a genuine update(), not a new row -- nothing
// in the product list ever showed two rows to compare, so the only record of
// what was lost is the activity log's captured "old" values.

function restoreContext(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Zelink Tech']);
    $itelHome = Store::create(['vendor_id' => $vendor->id, 'name' => 'Itel Home', 'is_default' => true]);
    $oraimoStore = Store::create(['vendor_id' => $vendor->id, 'name' => 'Oraimo Store']);
    $category = Category::firstOrCreate(['name' => 'Powerbank'], ['slug' => 'restore-test-powerbank']);

    return compact('vendor', 'itelHome', 'oraimoStore', 'category');
}

const BAD_IMPORT_TIME = '2026-08-22 14:59:40';

/** The real product, then the exact overwrite that happened in production. */
function overwrittenProduct(array $c): Product
{
    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'A1481', 'sku' => '1', 'brand' => 'Itel',
        'price' => 22500, 'cost_price' => 21500, 'store_id' => $c['itelHome']->id,
    ]);

    // A later import's row shares SKU "1" and updates this same row -- this is
    // ImportPreparer's real matching behaviour, reproduced directly here rather
    // than through the whole import pipeline, since it's the update itself
    // being tested, not how it was triggered. Pinned to the real incident's
    // timestamp so the command's time-window lookup has something real to find.
    test()->travelTo(\Illuminate\Support\Carbon::parse(BAD_IMPORT_TIME)->addSeconds(4));
    $product->update(['name' => 'O', 'price' => 1, 'cost_price' => 0, 'brand' => 'Oraimo', 'store_id' => $c['oraimoStore']->id]);
    test()->travelBack();

    return $product->fresh();
}

it('finds the original name and price from before the overwrite', function () {
    $c = restoreContext();
    $product = overwrittenProduct($c);

    expect($product->name)->toBe('O')
        ->and((float) $product->price)->toBe(1.0);

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id,
        'around' => BAD_IMPORT_TIME,
        '--store' => $c['oraimoStore']->id,
        '--brand' => 'Oraimo',
        '--to-store' => $c['itelHome']->id,
        '--to-brand' => 'Itel',
        '--force' => true,
    ])->assertSuccessful();

    $product->refresh();

    expect($product->name)->toBe('A1481')
        ->and((float) $product->price)->toBe(22500.0)
        ->and((float) $product->cost_price)->toBe(21500.0)
        ->and($product->brand)->toBe('Itel')
        ->and($product->store_id)->toBe($c['itelHome']->id);
});

it('leaves stock exactly as it is, since real counts happened against it', function () {
    $c = restoreContext();

    // stock_quantity must be set at creation, not via a later ->update() --
    // it is a mirror of the real per-store stock row, and only creation seeds
    // that row. Setting it afterward would write the mirror with nothing
    // backing it, and the store-move would correctly recompute it back to the
    // real (empty) total, which is not what this test is checking.
    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'A1481', 'sku' => '1', 'brand' => 'Itel',
        'price' => 22500, 'cost_price' => 21500, 'store_id' => $c['itelHome']->id,
        'stock_quantity' => 6,
    ]);

    test()->travelTo(\Illuminate\Support\Carbon::parse(BAD_IMPORT_TIME)->addSeconds(4));
    $product->update(['name' => 'O', 'price' => 1, 'cost_price' => 0, 'brand' => 'Oraimo', 'store_id' => $c['oraimoStore']->id]);
    test()->travelBack();

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id, 'around' => BAD_IMPORT_TIME,
        '--store' => $c['oraimoStore']->id,
        '--to-store' => $c['itelHome']->id, '--force' => true,
    ]);

    // The identity moved home; the physically counted quantity was not
    // invented by this command and is not this command's to touch.
    expect($product->fresh()->stock_quantity)->toBe(6);
});

it('does nothing without --force, and says so', function () {
    $c = restoreContext();
    $product = overwrittenProduct($c);

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id, 'around' => BAD_IMPORT_TIME,
        '--store' => $c['oraimoStore']->id,
    ])->expectsOutputToContain('Dry run');

    expect($product->fresh()->name)->toBe('O');
});

it('does not restore a product that was never actually overwritten', function () {
    $c = restoreContext();

    $clean = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'OCD-114MJ', 'sku' => '108', 'brand' => 'Oraimo',
        'price' => 990, 'store_id' => $c['oraimoStore']->id,
    ]);

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id, 'around' => BAD_IMPORT_TIME,
        '--store' => $c['oraimoStore']->id,
        '--brand' => 'Oraimo', '--force' => true,
    ])->expectsOutputToContain('nothing to restore');

    expect($clean->fresh()->name)->toBe('OCD-114MJ');
});

it('only restores fields the overwrite actually changed', function () {
    $c = restoreContext();

    $product = Product::create([
        'vendor_id' => $c['vendor']->id, 'category_id' => $c['category']->id,
        'name' => 'Original Name', 'sku' => '5', 'brand' => 'Itel',
        'price' => 5000, 'cost_price' => 4000, 'store_id' => $c['itelHome']->id,
    ]);

    // Only price changes this time -- name is untouched by the collision.
    test()->travelTo(\Illuminate\Support\Carbon::parse(BAD_IMPORT_TIME));
    $product->update(['price' => 1]);
    test()->travelBack();

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id, 'around' => BAD_IMPORT_TIME,
        '--store' => $c['itelHome']->id, '--force' => true,
    ]);

    $product->refresh();

    expect((float) $product->price)->toBe(5000.0)
        // Name was never part of the overwrite, so it is left exactly as it
        // already reads -- restoring an untouched field would be a second,
        // unrelated edit, not a repair of the collision.
        ->and($product->name)->toBe('Original Name');
});

it('never touches another vendor\'s products', function () {
    $c     = restoreContext();
    $other = restoreContext();

    $mine   = overwrittenProduct($c);
    $theirs = overwrittenProduct($other);

    $this->artisan('products:restore-overwritten', [
        'vendor' => $c['vendor']->id, 'around' => BAD_IMPORT_TIME,
        '--store' => $c['oraimoStore']->id,
        '--to-store' => $c['itelHome']->id, '--force' => true,
    ]);

    expect($mine->fresh()->name)->toBe('A1481')
        ->and($theirs->fresh()->name)->toBe('O');
});
