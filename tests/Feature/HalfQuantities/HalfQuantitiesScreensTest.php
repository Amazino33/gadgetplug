<?php

use App\Filament\Vendor\Pages\StockAdjustment;
use App\Filament\Vendor\Pages\StoreProfile;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use App\Support\Pos\TillProfile;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

// Phase 2 of half quantities: the screens. A shop that sells halves can sell,
// buy, count and return ½ crate; every other shop is refused a half exactly
// where it was refused one before, and sees nothing new.

function halvesScreensShop(bool $halves = true, array $product = []): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'                => $owner->id,
        'name'                   => ($halves ? 'Veruzo Wholesale ' : 'Corner Mart ').uniqid(),
        'pos_min_margin_percent' => 0,
        'allow_half_quantities'  => $halves,
    ]);
    VendorRoles::seedFor($vendor);

    $product = Product::create(array_merge([
        'vendor_id'                => $vendor->id,
        'store_id'                 => $vendor->defaultStore?->id,
        'category_id'              => Category::create(['name' => 'Drinks '.uniqid()])->id,
        'name'                     => 'Hero Lager',
        'base_unit'                => 'Crate',
        'price'                    => 10100,
        'wholesale_price'          => 9600,
        'cost_price'               => 8000,
        'allow_pos_price_override' => false,
        'stock_quantity'           => 20,
        'status'                   => 'published',
        'show_in_pos'              => true,
    ], $product));

    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    return compact('owner', 'vendor', 'product', 'base');
}

function halvesSale(array $ctx, int|float $qty, array $over = [])
{
    Sanctum::actingAs($ctx['owner']);

    return test()->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => [array_merge([
            'product_id'      => $ctx['product']->id,
            'product_name'    => 'Hero Lager',
            'unit_price'      => 10100,
            'quantity'        => $qty,
            'product_unit_id' => $ctx['base']->id,
            'unit_qty'        => $qty,
            'price_type'      => 'retail',
        ], $over)],
        'payment_method'  => 'cash',
        'amount_tendered' => 100000,
        'vat_rate'        => 0,
    ]);
}

// ─── Settings ────────────────────────────────────────────────────────────

it('turns half quantities on from Store Profile', function () {
    $ctx = halvesScreensShop(false);
    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(StoreProfile::class)
        ->assertFormSet(['allow_half_quantities' => false])
        ->fillForm(['allow_half_quantities' => true])
        ->call('save')
        ->assertHasNoErrors();

    expect($ctx['vendor']->fresh()->allow_half_quantities)->toBeTrue();
});

it('says which products are in the way when halves cannot be turned off', function () {
    $ctx = halvesScreensShop();
    ProductStoreStock::where('product_id', $ctx['product']->id)->update(['quantity' => 19.5]);

    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(StoreProfile::class)
        ->fillForm(['allow_half_quantities' => false])
        ->call('save')
        ->assertHasErrors(['data.allow_half_quantities']);

    expect($ctx['vendor']->fresh()->allow_half_quantities)->toBeTrue();
});

it('tells the till about halves, and sends half prices only to a halves shop', function () {
    $halves = halvesScreensShop(true, ['half_price' => 5200]);
    $plain  = halvesScreensShop(false);

    expect(TillProfile::for($halves['owner'], $halves['vendor'])['allow_half_quantities'])->toBeTrue()
        ->and(TillProfile::for($plain['owner'], $plain['vendor'])['allow_half_quantities'])->toBeFalse();

    $baseUnitOf = function (array $ctx): ?array {
        Sanctum::actingAs($ctx['owner']);

        $product = collect($this->getJson('/api/pos/products?vendor_id='.$ctx['vendor']->id)->assertOk()->json())
            ->firstWhere('id', $ctx['product']->id);

        return $product['units'][0] ?? null;
    };

    $unit      = $baseUnitOf($halves);
    $plainUnit = $baseUnitOf($plain);

    expect($unit['half_price'] ?? null)->toEqual(5200.0)
        ->and($plainUnit)->not->toBeNull()
        ->and(array_key_exists('half_price', $plainUnit))->toBeFalse();
});

// ─── Selling ─────────────────────────────────────────────────────────────

it('sells half a crate: ₦5,050 and 20 → 19½ on the shelf', function () {
    $ctx = halvesScreensShop();

    halvesSale($ctx, 0.5)->assertSuccessful();

    $line = PosSaleItem::latest('id')->first();

    expect((float) $line->total)->toBe(5050.0)
        ->and($line->quantity)->toBe(0.5)
        ->and((float) $line->half_unit_price)->toBe(5050.0)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(19.5);
});

it('sells 1½ crates at the set half price: ₦15,300', function () {
    $ctx = halvesScreensShop(true, ['half_price' => 5200]);

    halvesSale($ctx, 1.5)->assertSuccessful();

    expect((float) PosSaleItem::latest('id')->value('total'))->toBe(15300.0);
});

it('refuses half a crate from a shop that does not sell halves', function () {
    $ctx = halvesScreensShop(false);

    halvesSale($ctx, 0.5)->assertUnprocessable();

    expect($ctx['product']->fresh()->stock_quantity)->toBe(20);
});

it('refuses 0.3 even from a halves shop', function () {
    $ctx = halvesScreensShop();

    halvesSale($ctx, 0.3)->assertUnprocessable();
});

it('sells whole crates for a supermarket exactly as before', function () {
    $ctx = halvesScreensShop(false);

    halvesSale($ctx, 3)->assertSuccessful();

    $line = PosSaleItem::latest('id')->first();

    expect((float) $line->total)->toBe(30300.0)
        ->and($line->quantity)->toBe(3)
        ->and($line->half_unit_price)->toBeNull()
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(17);
});

// ─── Offline sync ────────────────────────────────────────────────────────

function halvesSync(array $ctx, string $offlineId, int|float $qty)
{
    Sanctum::actingAs($ctx['owner']);

    return test()->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'      => $offlineId,
            'items'           => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Hero Lager',
                'unit_price' => 10100, 'quantity' => $qty, 'total' => 5050,
                'product_unit_id' => $ctx['base']->id, 'unit_qty' => $qty,
            ]],
            'total'           => 5050,
            'vat_amount'      => 0,
            'payment_method'  => 'cash',
            'amount_tendered' => 5050,
            'completed_at'    => now()->subHour()->toDateTimeString(),
            'store_id'        => $ctx['vendor']->defaultStore?->id,
            'payments'        => null,
        ]],
    ]);
}

it('accepts a queued half sale after the shop turned halves off, and logs it', function () {
    $ctx = halvesScreensShop();
    $ctx['vendor']->fresh()->update(['allow_half_quantities' => false]);

    Log::spy();

    halvesSync($ctx, 'till-half-1', 0.5)
        ->assertSuccessful()
        ->assertJsonPath('results.0.status', 'synced');

    expect($ctx['product']->fresh()->stock_quantity)->toBe(19.5);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'half quantities'))->once();

    // The live path holds to the switch as it is now.
    halvesSale($ctx, 0.5)->assertUnprocessable();
});

it('refuses a half on sync from a shop that never sold halves', function () {
    $ctx = halvesScreensShop(false);

    halvesSync($ctx, 'till-half-2', 0.5)
        ->assertSuccessful()
        ->assertJsonPath('results.0.status', 'rejected');

    expect($ctx['product']->fresh()->stock_quantity)->toBe(20);
});

// ─── Returns ─────────────────────────────────────────────────────────────

it('refunds the half at what it cost, and the rest so it all adds up', function () {
    $ctx = halvesScreensShop(true, ['half_price' => 5200]);
    halvesSale($ctx, 1.5)->assertSuccessful();
    $sale = PosSale::latest('id')->first();

    Sanctum::actingAs($ctx['owner']);

    $first = $this->postJson("/api/pos/sales/{$sale->id}/return", [
        'items' => [['product_id' => $ctx['product']->id, 'quantity' => 0.5]], 'refund_method' => 'cash',
    ])->assertSuccessful();

    $second = $this->postJson("/api/pos/sales/{$sale->id}/return", [
        'items' => [['product_id' => $ctx['product']->id, 'quantity' => 1]], 'refund_method' => 'cash',
    ])->assertSuccessful();

    $refunds = App\Models\PosReturn::where('original_sale_id', $sale->id)->orderBy('id')->pluck('refund_amount')->map(fn ($a) => (float) $a);

    expect($refunds->all())->toBe([5200.0, 10100.0])
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(20);
});

// ─── Buying and counting ─────────────────────────────────────────────────

it('receives 10½ crates on the Purchase Terminal', function () {
    $ctx      = halvesScreensShop();
    $supplier = Supplier::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Brewery Depot']);

    $this->actingAs($ctx['owner'])->postJson("/purchases/{$ctx['vendor']->slug}/api/purchases", [
        'action'       => 'receive',
        'supplier_id'  => $supplier->id,
        'purchased_on' => now()->toDateString(),
        'payment'      => ['option' => 'none'],
        'lines'        => [['product_id' => $ctx['product']->id, 'quantity' => 10.5, 'unit_cost' => 8000, 'selling_price' => 10100]],
    ])->assertOk();

    expect($ctx['product']->fresh()->stock_quantity)->toBe(30.5);
});

it('refuses 10½ crates on the Purchase Terminal for a shop that does not sell halves', function () {
    $ctx      = halvesScreensShop(false);
    $supplier = Supplier::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Brewery Depot']);

    $this->actingAs($ctx['owner'])->postJson("/purchases/{$ctx['vendor']->slug}/api/purchases", [
        'action'       => 'receive',
        'supplier_id'  => $supplier->id,
        'purchased_on' => now()->toDateString(),
        'payment'      => ['option' => 'none'],
        'lines'        => [['product_id' => $ctx['product']->id, 'quantity' => 10.5, 'unit_cost' => 8000, 'selling_price' => 10100]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
});

it('counts 7½ crates on the stock adjustment screen', function () {
    $ctx = halvesScreensShop();
    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(StockAdjustment::class)
        ->set('reason', 'Shelf count')
        ->set("counts.{$ctx['product']->id}", '7½')
        ->call('setStock', $ctx['product']->id)
        ->assertHasNoErrors();

    expect($ctx['product']->fresh()->stock_quantity)->toBe(7.5);
});

it('still asks a supermarket for a whole number on the stock adjustment screen', function () {
    $ctx = halvesScreensShop(false);
    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(StockAdjustment::class)
        ->set('reason', 'Shelf count')
        ->set("counts.{$ctx['product']->id}", '7.5')
        ->call('setStock', $ctx['product']->id)
        ->assertHasErrors(["counts.{$ctx['product']->id}"]);

    expect($ctx['product']->fresh()->stock_quantity)->toBe(20);
});
