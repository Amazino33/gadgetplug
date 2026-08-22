<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Models\AuditSession;
use App\Models\BlindCountEntry;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// These drive submitAll() — the button a storekeeper actually presses — rather
// than calling the stock action underneath it.
//
// That distinction is the whole point of this file. The store-scoping work was
// covered by tests that invoked AdjustStockAction directly, so they passed
// while the page's own reconcile path carried a missing import for
// ProductStoreStock. php -l cannot see that (it is a runtime resolution, not a
// syntax error), and no test ever executed the line. In production every
// count then completed with its entries saved, zero audit lines, and no stock
// movement.

function reconcileVendor(): Vendor
{
    $vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Reconcile Vendor '.uniqid(),
    ]);

    // Solo counting, which is the path that failed in production.
    $vendor->update(['pos_blind_count_participants' => 1]);

    return $vendor->fresh();
}

function reconcileCounter(Vendor $vendor, Store $store): User
{
    $counter = User::factory()->create();
    $vendor->users()->attach($counter->id);
    $counter->stores()->attach($store->id);
    setPermissionsTeamId($vendor->id);
    $counter->assignRole('storekeeper');

    return $counter;
}

function reconcileProduct(Vendor $vendor, Store $home, int $onShelf): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Counted '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $onShelf,
        'status'         => 'published',
    ]);

    return $product->fresh();
}

/** A session mid-count, with the counter's numbers already recorded. */
function reconcileSession(Vendor $vendor, Store $store, User $counter, array $counts): BlindCountSession
{
    $session = BlindCountSession::create([
        'vendor_id'        => $vendor->id,
        'store_id'         => $store->id,
        'storekeeper_a_id' => $counter->id,
        'status'           => 'a_counting',
        'frequency'        => 'daily',
        'by_category'      => false,
        'product_order'    => array_keys($counts),
    ]);

    $position = 1;

    foreach ($counts as $productId => $count) {
        BlindCountEntry::create([
            'blind_count_session_id' => $session->id,
            'user_id'                => $counter->id,
            'product_id'             => $productId,
            'position'               => $position++,
            'count'                  => $count,
        ]);
    }

    return $session->fresh();
}

function actAsCounter(Vendor $vendor, User $counter): void
{
    test()->actingAs($counter);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

test('submitting a solo count writes an audit line for every product counted', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = reconcileVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);
    $counter = reconcileCounter($vendor, $branch);

    $matching = reconcileProduct($vendor, $branch, onShelf: 5);
    $short    = reconcileProduct($vendor, $branch, onShelf: 9);

    $session = reconcileSession($vendor, $branch, $counter, [
        $matching->id => 5,   // agrees with the shelf
        $short->id    => 6,   // three missing
    ]);

    actAsCounter($vendor, $counter);

    Livewire::test(BlindCount::class)->call('submitAll');

    $lines = AuditSession::where('blind_count_session_id', $session->id)->get()->keyBy('product_id');

    // The regression this file exists for: zero lines is what a missing import
    // produced, while the session still read "completed".
    expect($lines)->toHaveCount(2)
        ->and($session->fresh()->status)->toBe('completed');

    expect($lines[$matching->id]->status)->toBe('verified')
        ->and($lines[$matching->id]->system_quantity)->toBe(5)
        ->and($lines[$matching->id]->count_a)->toBe(5);

    expect($lines[$short->id]->status)->toBe('discrepancy')
        ->and($lines[$short->id]->system_quantity)->toBe(9)
        ->and($lines[$short->id]->count_a)->toBe(6);
});

test('the baseline each line is measured against is the counted branch shelf', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = reconcileVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);
    $counter = reconcileCounter($vendor, $branch);

    $product = reconcileProduct($vendor, $branch, onShelf: 4);

    // Another branch holds plenty of a different product; it must not colour
    // this branch's baseline.
    $other = Store::create(['vendor_id' => $vendor->id, 'name' => 'Elsewhere']);
    reconcileProduct($vendor, $other, onShelf: 500);

    $session = reconcileSession($vendor, $branch, $counter, [$product->id => 4]);

    actAsCounter($vendor, $counter);
    Livewire::test(BlindCount::class)->call('submitAll');

    $line = AuditSession::where('blind_count_session_id', $session->id)->first();

    expect($line)->not->toBeNull()
        ->and($line->system_quantity)->toBe(4)
        ->and($line->status)->toBe('verified');
});

test('a product with no stock row at the branch counts as an empty shelf, not an error', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = reconcileVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);
    $counter = reconcileCounter($vendor, $branch);

    $product = reconcileProduct($vendor, $branch, onShelf: 0);
    ProductStoreStock::where('product_id', $product->id)->delete();

    $session = reconcileSession($vendor, $branch, $counter, [$product->id => 2]);

    actAsCounter($vendor, $counter);
    Livewire::test(BlindCount::class)->call('submitAll');

    $line = AuditSession::where('blind_count_session_id', $session->id)->first();

    expect($line)->not->toBeNull()
        ->and($line->system_quantity)->toBe(0)
        // Two found where the system expected none is a real variance.
        ->and($line->status)->toBe('discrepancy');
});
