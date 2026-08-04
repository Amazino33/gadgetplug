<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;

// Deleting a vendor cascades into twenty-odd tables, so the important
// behaviour here is what the command refuses to do.

function duplicateVendors(User $owner, int $count, string $name = 'Geophone Gadgets and Store'): array
{
    $vendors = [];

    foreach (range(1, $count) as $i) {
        $vendors[] = Vendor::create([
            'user_id' => $owner->id,
            'name'    => $name,
        ]);
    }

    return $vendors;
}

function giveVendorAProduct(Vendor $vendor): Product
{
    $category = Category::firstOrCreate(['name' => 'Smartphones']);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Attached Product '.$vendor->id,
        'price'          => 1000,
        'stock_quantity' => 1,
        'status'         => 'published',
    ]);
}

it('reports without deleting anything unless forced', function () {
    $owner = User::factory()->create();
    duplicateVendors($owner, 4);

    $this->artisan('vendors:cleanup-duplicates')
        ->expectsOutputToContain('would be deleted')
        ->assertSuccessful();

    expect(Vendor::count())->toBe(4);
});

it('deletes the empty copies and keeps the one holding the data', function () {
    $owner   = User::factory()->create();
    $vendors = duplicateVendors($owner, 5);

    // The fourth copy is the one that was actually used.
    $realOne = $vendors[3];
    giveVendorAProduct($realOne);

    $this->artisan('vendors:cleanup-duplicates', ['--force' => true])->assertSuccessful();

    expect(Vendor::count())->toBe(1)
        ->and(Vendor::first()->id)->toBe($realOne->id)
        ->and(Product::where('vendor_id', $realOne->id)->count())->toBe(1);
});

it('never deletes a duplicate that has data of its own', function () {
    $owner   = User::factory()->create();
    $vendors = duplicateVendors($owner, 3);

    // Two of the three have real data; neither may be removed, even though only
    // one can be the designated keeper.
    giveVendorAProduct($vendors[0]);
    giveVendorAProduct($vendors[1]);

    $this->artisan('vendors:cleanup-duplicates', ['--force' => true])->assertSuccessful();

    expect(Vendor::whereIn('id', [$vendors[0]->id, $vendors[1]->id])->count())->toBe(2)
        ->and(Vendor::find($vendors[2]->id))->toBeNull()
        ->and(Product::count())->toBe(2);
});

it('leaves vendors that are not duplicates alone', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    Vendor::create(['user_id' => $ownerA->id, 'name' => 'Leisure Hub']);
    Vendor::create(['user_id' => $ownerB->id, 'name' => 'Chip Gadget']);
    // Same name, different owner — a different business, not a duplicate.
    Vendor::create(['user_id' => $ownerB->id, 'name' => 'Leisure Hub']);

    $this->artisan('vendors:cleanup-duplicates', ['--force' => true])->assertSuccessful();

    expect(Vendor::count())->toBe(3);
});

it('does not delete a lone vendor even when it is empty', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Solo Store']);

    $this->artisan('vendors:cleanup-duplicates', ['--force' => true])->assertSuccessful();

    expect(Vendor::count())->toBe(1);
});

it('can be told a table does not count as real data', function () {
    $owner   = User::factory()->create();
    $vendors = duplicateVendors($owner, 3);

    // Every vendor gets the same seeded message templates, so without --ignore
    // no duplicate is ever removable.
    foreach ($vendors as $vendor) {
        App\Models\MessageTemplate::create([
            'vendor_id'      => $vendor->id,
            'key'            => 'order_confirmed',
            'recipient_type' => 'customer',
            'channel'        => 'whatsapp',
            'body'           => 'Default body',
        ]);
    }

    giveVendorAProduct($vendors[0]);

    // Without the flag: nothing is safe to remove.
    $this->artisan('vendors:cleanup-duplicates', ['--force' => true])->assertSuccessful();
    expect(Vendor::count())->toBe(3);

    // With it: the copies carrying nothing but templates go.
    $this->artisan('vendors:cleanup-duplicates', [
        '--force'  => true,
        '--ignore' => ['message_templates'],
    ])->assertSuccessful();

    expect(Vendor::count())->toBe(1)
        ->and(Vendor::first()->id)->toBe($vendors[0]->id);
});

it('still refuses when an ignored table is not the only thing attached', function () {
    $owner   = User::factory()->create();
    $vendors = duplicateVendors($owner, 2);

    giveVendorAProduct($vendors[0]);
    // The second copy has a real product too, so ignoring templates must not
    // make it disposable.
    giveVendorAProduct($vendors[1]);

    $this->artisan('vendors:cleanup-duplicates', [
        '--force'  => true,
        '--ignore' => ['message_templates'],
    ])->assertSuccessful();

    expect(Vendor::count())->toBe(2);
});
