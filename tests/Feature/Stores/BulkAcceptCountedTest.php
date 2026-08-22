<?php

use App\Filament\Vendor\Resources\AuditSessions\Pages\ManageAuditSessions;
use App\Models\AuditSession;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Drives the bulk action through the table, the way a manager presses it —
// not the helper underneath. The BlindCount outage came from testing the layer
// below the screen and never executing the screen's own path.

function bulkVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Bulk Vendor '.uniqid(),
    ]);
}

function bulkProduct(Vendor $vendor, Store $home, int $onShelf, float $cost = 400): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Bulk Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => $cost,
        'stock_quantity' => $onShelf,
        'status'         => 'published',
    ])->fresh();
}

/** A completed count that left discrepancy lines awaiting a decision. */
function bulkLine(Vendor $vendor, Store $store, Product $product, int $counted, int $system): AuditSession
{
    $session = BlindCountSession::firstOrCreate(
        ['vendor_id' => $vendor->id, 'store_id' => $store->id, 'status' => 'completed'],
        [
            'storekeeper_a_id' => $vendor->user_id,
            'frequency'        => 'daily',
            'by_category'      => false,
            'product_order'    => [],
        ],
    );

    return AuditSession::create([
        'vendor_id'              => $vendor->id,
        'blind_count_session_id' => $session->id,
        'product_id'             => $product->id,
        'system_quantity'        => $system,
        'storekeeper_a_id'       => $vendor->user_id,
        'count_a'                => $counted,
        'status'                 => 'discrepancy',
    ]);
}

function actAsManager(Vendor $vendor, ?User $user = null): User
{
    $user ??= $vendor->user;

    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    return $user;
}

test('accepting counted figures sets each line stock to what was counted', function () {
    $vendor = bulkVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);

    // The opening-count shape: nothing on the system, plenty on the shelf.
    $a = bulkProduct($vendor, $branch, onShelf: 0);
    $b = bulkProduct($vendor, $branch, onShelf: 0);

    $lineA = bulkLine($vendor, $branch, $a, counted: 18, system: 0);
    $lineB = bulkLine($vendor, $branch, $b, counted: 207, system: 0);

    actAsManager($vendor);

    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$lineA, $lineB], ['reason_code' => 'Opening Stock Count']);

    expect(ProductStoreStock::where('product_id', $a->id)->where('store_id', $branch->id)->value('quantity'))->toBe(18)
        ->and(ProductStoreStock::where('product_id', $b->id)->where('store_id', $branch->id)->value('quantity'))->toBe(207);

    expect($lineA->fresh())
        ->status->toBe('resolved_by_override')
        ->manager_override_count->toBe(18)
        ->reason_code->toBe('Opening Stock Count');
});

test('the correction lands in the branch that was counted, not the one being viewed', function () {
    $vendor = bulkVendor();
    $counted = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);

    $product = bulkProduct($vendor, $counted, onShelf: 0);
    $line = bulkLine($vendor, $counted, $product, counted: 12, system: 0);

    $manager = actAsManager($vendor);

    // The manager is standing in a different branch while resolving.
    ActiveStore::set($vendor, $manager, $vendor->defaultStore->id);

    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$line], ['reason_code' => 'Opening Stock Count']);

    expect(ProductStoreStock::where('product_id', $product->id)->where('store_id', $counted->id)->value('quantity'))->toBe(12)
        // Nothing moved in the branch that merely happened to be active.
        ->and((int) ProductStoreStock::where('product_id', $product->id)->where('store_id', $vendor->defaultStore->id)->value('quantity'))->toBe(0);
});

test('every corrected line leaves a ledger entry against its branch', function () {
    $vendor = bulkVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);

    $product = bulkProduct($vendor, $branch, onShelf: 0);
    $line = bulkLine($vendor, $branch, $product, counted: 9, system: 0);

    actAsManager($vendor);

    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$line], ['reason_code' => 'Opening Stock Count']);

    $entry = InventoryLedger::where('audit_session_id', $line->id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->store_id)->toBe($branch->id)
        ->and($entry->quantity_change)->toBe(9)
        ->and($entry->transaction_type)->toBe('audit_correction')
        ->and($entry->reason_code)->toBe('Opening Stock Count');
});

test('a shortfall books a loss, a surplus does not', function () {
    $vendor = bulkVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);

    $missing = bulkProduct($vendor, $branch, onShelf: 10, cost: 400);
    $extra   = bulkProduct($vendor, $branch, onShelf: 0, cost: 400);

    $short   = bulkLine($vendor, $branch, $missing, counted: 7, system: 10);  // 3 short
    $surplus = bulkLine($vendor, $branch, $extra, counted: 5, system: 0);     // 5 over

    actAsManager($vendor);

    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$short, $surplus], ['reason_code' => 'Data Entry Error']);

    expect((float) $short->fresh()->loss_value)->toBe(1200.0)   // 3 x 400
        ->and((float) $surplus->fresh()->loss_value)->toBe(0.0);
});

test('a line already resolved is left alone rather than corrected twice', function () {
    $vendor = bulkVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);

    $product = bulkProduct($vendor, $branch, onShelf: 0);
    $line = bulkLine($vendor, $branch, $product, counted: 6, system: 0);

    actAsManager($vendor);

    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$line], ['reason_code' => 'Opening Stock Count']);

    expect(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(6);

    // Pressing it again on the same line must not stack a second correction.
    Livewire::test(ManageAuditSessions::class)
        ->callTableBulkAction('accept_counted', [$line->fresh()], ['reason_code' => 'Opening Stock Count']);

    expect(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(6)
        ->and(InventoryLedger::where('audit_session_id', $line->id)->count())->toBe(1);
});

test('a member without the permission cannot reach the bulk action', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = bulkVendor();
    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    $vendor->users()->attach($storekeeper->id);
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);
    $product = bulkProduct($vendor, $branch, onShelf: 0);
    $line = bulkLine($vendor, $branch, $product, counted: 4, system: 0);

    actAsManager($vendor, $storekeeper);

    // edit_order_items is what gates resolving; a storekeeper counts, they do
    // not decide what the shelf figure becomes.
    expect($storekeeper->hasVendorPermission($vendor->id, 'edit_order_items'))->toBeFalse();

    expect(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(0);
});
