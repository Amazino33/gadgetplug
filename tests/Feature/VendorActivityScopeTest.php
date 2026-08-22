<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activityVendor(): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Activity Store ' . uniqid()]);
}

function activityProduct(Vendor $vendor): Product
{
    return Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat ' . uniqid()])->id,
        'name'        => 'Logged Widget',
        'price'       => 1000,
        'status'      => 'published',
    ]);
}

test('a log call that names no vendor still lands in that vendor feed, via the subject', function () {
    $vendor = activityVendor();
    $product = activityProduct($vendor);

    // No ->tap(), exactly the shape that was silently dropping rows.
    activity()->performedOn($product)->log('Something happened');

    $entry = VendorActivity::where('description', 'Something happened')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->vendor_id)->toBe($vendor->id);
});

test('an explicit vendor on the call still wins over the resolver', function () {
    $vendor = activityVendor();
    $other  = activityVendor();

    // Supplier is vendor-owned but defines no tapActivity of its own, so the
    // only thing competing with the explicit tap is the resolver. (A model that
    // does define tapActivity overrides explicit taps — Spatie applies the
    // subject's tap last — which predates this resolver.)
    $supplier = \App\Models\Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Tap Test Supplier']);

    activity()->performedOn($supplier)
        ->tap(fn ($a) => $a->vendor_id = $other->id)
        ->log('Explicitly filed elsewhere');

    expect(VendorActivity::where('description', 'Explicitly filed elsewhere')->first()->vendor_id)
        ->toBe($other->id);
});

test('an action on the vendor itself is filed under that vendor', function () {
    $vendor = activityVendor();

    activity()->performedOn($vendor)->log('Vendor touched');

    expect(VendorActivity::where('description', 'Vendor touched')->first()->vendor_id)->toBe($vendor->id);
});

test('a store-specific subject also stamps the store', function () {
    $vendor = activityVendor();
    $store  = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch A']);

    activity()->performedOn($store)->log('Store touched');

    $entry = VendorActivity::where('description', 'Store touched')->first();

    expect($entry->vendor_id)->toBe($vendor->id)
        ->and($entry->store_id)->toBe($store->id);
});

test('a vendor-level action records no store rather than inventing one', function () {
    $vendor = activityVendor();

    // Inviting a team member is a vendor-wide act with no branch behind it.
    activity()->performedOn($vendor)->log('Team member invited');

    expect(VendorActivity::where('description', 'Team member invited')->first()->store_id)->toBeNull();
});

test('a product edit is attributed to the store that product belongs to', function () {
    $vendor  = activityVendor();
    $store   = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    $product = activityProduct($vendor);
    $product->forceFill(['store_id' => $store->id])->saveQuietly();

    activity()->performedOn($product->fresh())->log('Price changed');

    expect(VendorActivity::where('description', 'Price changed')->first()->store_id)->toBe($store->id);
});

test('the causer resolves the vendor when there is no subject and no ambiguity', function () {
    $vendor = activityVendor();
    $member = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$member->id]);

    activity()->causedBy($member)->log('Did a thing');

    expect(VendorActivity::where('description', 'Did a thing')->first()->vendor_id)->toBe($vendor->id);
});

test('a causer in two vendors is left unscoped rather than filed under a guess', function () {
    $a = activityVendor();
    $b = activityVendor();

    $member = User::factory()->create();
    $a->users()->syncWithoutDetaching([$member->id]);
    $b->users()->syncWithoutDetaching([$member->id]);

    activity()->causedBy($member)->log('Ambiguous thing');

    expect(VendorActivity::where('description', 'Ambiguous thing')->first()->vendor_id)->toBeNull();
});

test('model logging still stamps the vendor as it did before', function () {
    $vendor  = activityVendor();
    $product = activityProduct($vendor);

    $product->update(['price' => 2500]);

    $entry = VendorActivity::where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('event', 'updated')
        ->first();

    expect($entry)->not->toBeNull()->and($entry->vendor_id)->toBe($vendor->id);
});
