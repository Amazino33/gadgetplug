<?php

use App\Filament\Vendor\Pages\StockAdjustment;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function adjVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Adjust Store', 'slug' => 'adjust-store']);

    VendorRoles::seedFor($vendor);

    $manager = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $manager->assignRole('inventory_manager');
    $vendor->users()->attach($manager->id);

    return compact('owner', 'vendor', 'manager');
}

function adjProduct(Vendor $vendor, string $sku, int $stock = 0, ?string $barcode = null): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Adjust Cat'])->id,
        'name'           => "Product {$sku}",
        'sku'            => $sku,
        'barcode'        => $barcode,
        'price'          => 1000,
        'stock_quantity' => $stock,
        'status'         => 'published',
    ]);
}

function asManager(array $data): void
{
    test()->actingAs($data['manager']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('pasted rows are matched to products before anything changes', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    $component = Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->call('buildPreview');

    $preview = $component->get('preview');

    expect($preview)->toHaveCount(1)
        ->and($preview[0]['product_id'])->toBe($p->id)
        ->and($preview[0]['current'])->toBe(0)
        ->and($preview[0]['target'])->toBe(12)
        ->and($preview[0]['change'])->toBe(12)
        // Preview must not touch stock
        ->and($p->fresh()->stock_quantity)->toBe(0);
});

test('applying sets stock to the sheet figure and writes a ledger entry', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->set('reason', 'Opening stock from vendor sheet')
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(12);

    $ledger = InventoryLedger::where('product_id', $p->id)->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->transaction_type)->toBe('stock_adjustment')
        ->and($ledger->quantity_change)->toBe(12)
        ->and($ledger->description)->toBe('Opening stock from vendor sheet')
        ->and($ledger->user_id)->toBe($data['manager']->id);
});

// The sheet states what is on the shelf, so 12 means "make it 12", not "add 12".
test('quantities are absolute, not added to what is already there', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 20);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(12);

    // Recorded as the movement it really was: down 8
    expect(InventoryLedger::where('product_id', $p->id)->first()->quantity_change)->toBe(-8);
});

test('a product already at the right figure is left alone', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 12);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(12)
        ->and(InventoryLedger::where('product_id', $p->id)->count())->toBe(0);
});

test('barcodes work as well as SKUs', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-9', 0, '6901443000123');
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "6901443000123\t7")
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(7);
});

test('commas and plain spaces are accepted, not just tabs', function () {
    $data = adjVendor();
    $a    = adjProduct($data['vendor'], 'SKU-A', 0);
    $b    = adjProduct($data['vendor'], 'SKU-B', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-A, 5\nSKU-B 9")
        ->call('buildPreview')
        ->call('apply');

    expect($a->fresh()->stock_quantity)->toBe(5)
        ->and($b->fresh()->stock_quantity)->toBe(9);
});

test('unknown and unreadable lines are reported and skipped, good lines still apply', function () {
    $data = adjVendor();
    $good = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    $component = Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t4\nNOT-A-PRODUCT\t9\nrubbish-line")
        ->call('buildPreview');

    $preview = $component->get('preview');

    expect($preview[1]['error'])->not->toBeNull()
        ->and($preview[2]['error'])->not->toBeNull();

    $component->call('apply');

    expect($good->fresh()->stock_quantity)->toBe(4)
        ->and(InventoryLedger::count())->toBe(1);
});

test('a negative quantity is refused', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 5);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t-3")
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(5)
        ->and(InventoryLedger::count())->toBe(0);
});

test('another vendor product cannot be reached', function () {
    $data  = adjVendor();
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other-adj']);
    $theirs = adjProduct($other, 'SKU-X', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-X\t50")
        ->call('buildPreview')
        ->call('apply');

    expect($theirs->fresh()->stock_quantity)->toBe(0)
        ->and(InventoryLedger::count())->toBe(0);
});

test('applying without previewing first does nothing', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(0);
});

test('an empty reason blocks the apply', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t12")
        ->set('reason', '   ')
        ->call('buildPreview')
        ->call('apply')
        ->assertHasErrors('reason');

    expect($p->fresh()->stock_quantity)->toBe(0);
});

test('a duplicate line is applied once, not twice', function () {
    $data = adjVendor();
    $p    = adjProduct($data['vendor'], 'SKU-1', 0);
    asManager($data);

    Livewire::test(StockAdjustment::class)
        ->set('pasted', "SKU-1\t10\nSKU-1\t99")
        ->call('buildPreview')
        ->call('apply');

    expect($p->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryLedger::where('product_id', $p->id)->count())->toBe(1);
});

// Setting stock by hand bypasses procurement and counting, so it must not ride
// on manage_inventory, which every storekeeper holds.
test('a storekeeper cannot reach the page', function () {
    $data = adjVendor();

    $keeper = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $keeper->assignRole('storekeeper');

    $this->actingAs($keeper);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    expect(StockAdjustment::canAccess())->toBeFalse();
});

test('an inventory manager and the owner can reach the page', function () {
    $data = adjVendor();

    asManager($data);
    expect(StockAdjustment::canAccess())->toBeTrue();

    $this->actingAs($data['owner']);
    Filament::setTenant($data['vendor']);
    expect(StockAdjustment::canAccess())->toBeTrue();
});
