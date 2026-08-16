<?php

use App\Filament\Vendor\Pages\StoreSelector;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\StoreStockMetrics;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function valuationVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Valuation Vendor '.uniqid(),
    ]);
}

function valuationProduct(Vendor $vendor, ?float $cost, float $price, int $qty, ?Store $store = null): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Valuation Product '.uniqid(),
        'price'          => $price,
        'cost_price'     => $cost,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    $target = $store ?? $vendor->defaultStore;

    if ($target->id === $vendor->defaultStore->id) {
        ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => $qty]);
    } else {
        ProductStoreStock::where('product_id', $product->id)->delete();
        ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $target->id, 'quantity' => $qty]);
    }

    return $product->fresh();
}

function metricsFor(Store $store): object
{
    return StoreStockMetrics::forStores([$store->id])[$store->id] ?? StoreStockMetrics::empty();
}

// ─── The totals ─────────────────────────────────────────────────────

test('cost and retail value sum across the store rows', function () {
    $vendor = valuationVendor();
    valuationProduct($vendor, cost: 400.0, price: 1000.0, qty: 10);
    valuationProduct($vendor, cost: 250.0, price: 600.0, qty: 4);

    $m = metricsFor($vendor->defaultStore);

    expect($m->cost_value)->toBe(5000.0)     // 4000 + 1000
        ->and($m->retail_value)->toBe(12400.0) // 10000 + 2400
        ->and($m->product_count)->toBe(2)
        ->and($m->units)->toBe(14)
        ->and($m->missing_cost_count)->toBe(0);
});

test('a product with no cost price is excluded from the cost total, not valued at zero', function () {
    $vendor = valuationVendor();
    valuationProduct($vendor, cost: 400.0, price: 1000.0, qty: 10);
    valuationProduct($vendor, cost: null, price: 900.0, qty: 5);

    $m = metricsFor($vendor->defaultStore);

    expect($m->cost_value)->toBe(4000.0)
        // Retail is unaffected — price is never null.
        ->and($m->retail_value)->toBe(14500.0)
        ->and($m->missing_cost_count)->toBe(1)
        // The uncosted product still counts as stock the store holds.
        ->and($m->product_count)->toBe(2)
        ->and($m->units)->toBe(15);
});

test('an uncosted product holding no stock raises no warning', function () {
    $vendor = valuationVendor();
    valuationProduct($vendor, cost: 400.0, price: 1000.0, qty: 10);
    valuationProduct($vendor, cost: null, price: 900.0, qty: 0);

    // Nothing on the shelf distorts nothing, so there is nothing to flag.
    expect(metricsFor($vendor->defaultStore)->missing_cost_count)->toBe(0)
        ->and(metricsFor($vendor->defaultStore)->cost_value)->toBe(4000.0);
});

test('every product uncosted gives a zero cost value and a full count', function () {
    $vendor = valuationVendor();
    valuationProduct($vendor, cost: null, price: 1000.0, qty: 3);
    valuationProduct($vendor, cost: null, price: 500.0, qty: 2);

    $m = metricsFor($vendor->defaultStore);

    expect($m->cost_value)->toBe(0.0)
        ->and($m->missing_cost_count)->toBe(2)
        ->and($m->retail_value)->toBe(4000.0);
});

test('valuation is per store and never bleeds across branches', function () {
    $vendor = valuationVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    valuationProduct($vendor, cost: 400.0, price: 1000.0, qty: 10);
    valuationProduct($vendor, cost: null, price: 700.0, qty: 6, store: $branch);

    expect(metricsFor($vendor->defaultStore))
        ->cost_value->toBe(4000.0)
        ->missing_cost_count->toBe(0);

    expect(metricsFor($branch))
        ->cost_value->toBe(0.0)
        ->retail_value->toBe(4200.0)
        ->missing_cost_count->toBe(1);
});

test('a store holding nothing values at zero rather than erroring', function () {
    $vendor = valuationVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Empty Branch']);

    expect(metricsFor($branch))
        ->cost_value->toBe(0.0)
        ->retail_value->toBe(0.0)
        ->product_count->toBe(0)
        ->missing_cost_count->toBe(0);
});

// ─── The card honours view_cost_price ───────────────────────────────

test('an owner sees the cost value and the excluded-product warning', function () {
    $vendor = valuationVendor();
    valuationProduct($vendor, cost: null, price: 900.0, qty: 5);

    test()->actingAs($vendor->user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    Livewire::test(StoreSelector::class)
        ->assertSee('Value (cost)')
        ->assertSee('Excludes 1 product with no cost price');
});

test('a member without view_cost_price sees neither the cost value nor the warning', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = valuationVendor();
    valuationProduct($vendor, cost: null, price: 900.0, qty: 5);

    VendorRoles::seedFor($vendor);
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    $member->stores()->attach($vendor->defaultStore->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('storekeeper');

    test()->actingAs($member);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    Livewire::test(StoreSelector::class)
        ->assertSee('Value (retail)')
        ->assertDontSee('Value (cost)')
        // The warning reveals that cost data exists and is incomplete, which is
        // itself a cost signal — it travels with the figure it qualifies.
        ->assertDontSee('no cost price');
});
