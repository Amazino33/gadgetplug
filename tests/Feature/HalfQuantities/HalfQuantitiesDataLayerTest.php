<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\StockCostLayer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\StoreAllocator;
use App\Services\Pos\HalfPricing;
use App\Services\Pos\PosPriceFloor;
use App\Services\Pos\SaleLineResolver;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Phase 1 of half quantities: the data layer. Wholesale drink shops sell ½,
// 1½, 2½ crates; every other shop must see no change at all. These run on
// SQLite and, through phpunit.mysql.xml, on MySQL — the DECIMAL columns and
// the CAST in availableSql() are exactly what SQLite cannot catch.

function halvesShop(bool $halves = true, array $product = []): array
{
    $owner = User::factory()->create();

    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name' => $halves ? 'Veruzo Wholesale Drink' : 'Corner Supermarket',
        'pos_min_margin_percent' => 0,
        'allow_half_quantities' => $halves,
    ]);

    $product = Product::create(array_merge([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Drinks '.uniqid()])->id,
        'name' => 'Hero Lager',
        'base_unit' => 'Crate',
        'price' => 10100,
        'wholesale_price' => 9600,
        'cost_price' => 8000,
        'allow_pos_price_override' => false,
        'stock_quantity' => 20,
        'status' => 'published',
        'show_in_pos' => true,
    ], $product));

    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    return compact('owner', 'vendor', 'product', 'base');
}

function shelf(Product $product): ProductStoreStock
{
    return ProductStoreStock::where('product_id', $product->id)->firstOrFail();
}

function line(array $ctx, int|float|string $qty, string $type = 'retail', ?float $price = null): array
{
    return [
        'product_id' => $ctx['product']->id,
        'product_name' => 'Hero Lager',
        'unit_price' => $price ?? ($type === 'wholesale' ? 9600 : 10100),
        'quantity' => $qty,
        'product_unit_id' => $ctx['base']->id,
        'unit_qty' => $qty,
        'price_type' => $type,
    ];
}

function resolveOne(array $ctx, array $line, ?bool $allowHalves = null): array
{
    return app(SaleLineResolver::class)->resolve($ctx['vendor']->fresh(), [$line], $allowHalves)[0];
}

// ─── Quantity helper ─────────────────────────────────────────────────────

it('formats halves for a halves shop and plain numbers for everyone else', function () {
    expect(Quantity::format(0.5, true))->toBe('½')
        ->and(Quantity::format(1.5, true))->toBe('1½')
        ->and(Quantity::format(12, true))->toBe('12')
        ->and(Quantity::format('12.000', true))->toBe('12')
        ->and(Quantity::format(-1.5, true))->toBe('-1½')
        ->and(Quantity::format(1.5, false))->toBe('1.5')
        ->and(Quantity::format(12, false))->toBe('12');
});

it('accepts half-steps only when halves are allowed, and never zero', function () {
    expect(Quantity::errorFor(true, 0.5))->toBeNull()
        ->and(Quantity::errorFor(true, 1.5))->toBeNull()
        ->and(Quantity::errorFor(true, 0.3))->not->toBeNull()
        ->and(Quantity::errorFor(true, 0))->not->toBeNull()
        ->and(Quantity::errorFor(false, 0.5))->not->toBeNull()
        ->and(Quantity::errorFor(false, 2))->toBeNull();
});

it('hands back an int for a whole quantity and a float only for a fraction', function () {
    expect(Quantity::of('19.000'))->toBe(19)
        ->and(Quantity::of('19.500'))->toBe(19.5)
        ->and(Quantity::of(7))->toBe(7);
});

// ─── The columns and the cast ────────────────────────────────────────────

it('keeps whole stock exactly as it was, read back as an int', function () {
    $ctx = halvesShop(false);

    $product = Product::find($ctx['product']->id);

    // Strictly the int 20, not "20.000" and not 20.0 — a supermarket's till
    // and API must not see the column change type underneath them.
    expect($product->stock_quantity)->toBe(20)
        ->and(shelf($product)->quantity)->toBe(20)
        ->and($product->toArray()['stock_quantity'])->toBe(20)
        ->and(json_encode(['q' => shelf($product)->quantity]))->toBe('{"q":20}');
});

it('stores and reads back a half exactly', function () {
    $ctx = halvesShop();

    shelf($ctx['product'])->update(['quantity' => 19.5]);

    expect(shelf($ctx['product'])->quantity)->toBe(19.5)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(19.5);
});

it('computes on hand less reserved in SQL without rounding a half away', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19.5]);

    $value = DB::table('products')
        ->where('id', $ctx['product']->id)
        ->selectRaw(Quantity::availableSql().' as available')
        ->value('available');

    // The MySQL bug this replaces: CAST(19.5 AS SIGNED) is 20.
    expect(Quantity::of($value))->toBe(19.5);
});

it('shows why CAST AS SIGNED had to go, on MySQL', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19.5]);

    $signed = DB::table('products')->where('id', $ctx['product']->id)
        ->selectRaw('CAST(stock_quantity AS SIGNED) as v')->value('v');

    expect((int) $signed)->toBe(20);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'MySQL only: SQLite has no SIGNED rounding to demonstrate.');

// ─── Stock moves in halves ───────────────────────────────────────────────

it('takes half a crate off the shelf, the mirror and the ledger', function () {
    $ctx = halvesShop();

    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id,
        quantityChanged: -0.5,
        transactionType: 'pos_sale',
    );

    expect(shelf($ctx['product'])->quantity)->toBe(19.5)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(19.5)
        ->and(InventoryLedger::where('product_id', $ctx['product']->id)->latest('id')->value('quantity_change'))->toBe(-0.5);
});

it('costs half a crate at half the crate cost', function () {
    $ctx = halvesShop();

    StockCostLayer::where('product_id', $ctx['product']->id)->delete();
    StockCostLayer::create([
        'product_id' => $ctx['product']->id, 'store_id' => shelf($ctx['product'])->store_id,
        'unit_cost' => 8000, 'quantity_received' => 20, 'quantity_remaining' => 20, 'received_at' => now(),
    ]);

    $ledger = app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id,
        quantityChanged: -0.5,
        transactionType: 'pos_sale',
    );

    expect((float) $ledger->cost_total)->toBe(4000.0)
        ->and(StockCostLayer::where('product_id', $ctx['product']->id)->value('quantity_remaining'))->toBe(19.5);
});

// ─── The online store stays whole ────────────────────────────────────────

it('offers only whole units online: 19½ on the shelf is 19 to order', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19.5]);
    $product = $ctx['product']->fresh();

    expect($product->available_stock)->toBe(19)
        ->and($product->storeAvailable())->toBe(19.5)
        ->and(StoreAllocator::combinedAvailable($ctx['vendor']->id, $product->id))->toBe(19)
        ->and(StoreAllocator::allocate($ctx['vendor']->id, $product->id, 20))->toBe([])
        ->and(array_sum(StoreAllocator::allocate($ctx['vendor']->id, $product->id, 19)))->toBe(19);
});

it('keeps a product with only half a crate left out of the online catalogue', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 0.5]);

    expect(Product::whereKey($ctx['product']->id)->inStockForSale()->exists())->toBeFalse()
        ->and($ctx['product']->fresh()->available_stock)->toBe(0);

    shelf($ctx['product'])->update(['quantity' => 1]);

    expect(Product::whereKey($ctx['product']->id)->inStockForSale()->exists())->toBeTrue();
});

// ─── Half pricing ────────────────────────────────────────────────────────

it('charges exactly half when no half price is set: ½ crate of ₦10,100 is ₦5,050', function () {
    $ctx = halvesShop();

    $half = resolveOne($ctx, line($ctx, 0.5));
    $andHalf = resolveOne($ctx, line($ctx, 1.5));

    expect($half['line_gross'])->toBe(5050.0)
        ->and($half['half_unit_price'])->toBe(5050.0)
        ->and($half['quantity'])->toBe(0.5)
        ->and($andHalf['line_gross'])->toBe(15150.0);
});

it('charges the set half price: ½ crate ₦5,200, 1½ crates ₦15,300', function () {
    $ctx = halvesShop(true, ['half_price' => 5200]);

    expect($ctx['base']->fresh()->half_price)->toBe('5200.00')
        ->and(resolveOne($ctx, line($ctx, 0.5))['line_gross'])->toBe(5200.0)
        ->and(resolveOne($ctx, line($ctx, 1.5))['line_gross'])->toBe(15300.0);
});

it('halves the wholesale price, whatever half price is set', function () {
    $ctx = halvesShop(true, ['half_price' => 5200]);

    expect(resolveOne($ctx, line($ctx, 0.5, 'wholesale'))['line_gross'])->toBe(4800.0)
        ->and(resolveOne($ctx, line($ctx, 2.5, 'wholesale'))['line_gross'])->toBe(24000.0);
});

it('applies the half price on a bigger unit too', function () {
    $ctx = halvesShop();
    $pack = ProductUnit::create([
        'product_id' => $ctx['product']->id, 'name' => 'Pallet', 'conversion_factor' => 50,
        'price' => 500000, 'half_price' => 260000,
    ]);

    $line = resolveOne($ctx, [
        'product_id' => $ctx['product']->id, 'product_name' => 'Hero Lager',
        'unit_price' => 500000, 'quantity' => 25, 'product_unit_id' => $pack->id, 'unit_qty' => 0.5,
    ]);

    expect($line['line_gross'])->toBe(260000.0)
        ->and($line['quantity'])->toBe(25);
});

it('leaves a whole line exactly as it always was, with no half price stored', function () {
    $ctx = halvesShop(false);

    $line = resolveOne($ctx, line($ctx, 3));

    expect($line['line_gross'])->toBe(30300.0)
        ->and($line['half_unit_price'])->toBeNull()
        ->and($line['quantity'])->toBe(3);
});

it('refuses 0.3 and 0 from a halves shop', function (float $qty) {
    $ctx = halvesShop();

    expect(fn () => resolveOne($ctx, line($ctx, $qty)))->toThrow(ValidationException::class);
})->with([0.3, 0.0]);

it('refuses half a crate from a shop that does not sell halves', function () {
    $ctx = halvesShop(false);

    expect(fn () => resolveOne($ctx, line($ctx, 0.5)))->toThrow(ValidationException::class);
});

it('puts a half line through HalfPricing exactly as the rules say', function () {
    expect(HalfPricing::lineTotal(10100, 5200, 1.5))->toBe(15300.0)
        ->and(HalfPricing::lineTotal(10100, null, 0.5))->toBe(5050.0)
        ->and(HalfPricing::lineTotal(10100, 5200, 3))->toBe(30300.0)
        ->and(HalfPricing::floorTotal(8000, 1.5))->toBe(12000.0);
});

// ─── The price floor on a half ───────────────────────────────────────────

it('holds a haggled half line to half the unit floor', function () {
    $ctx = halvesShop(true, ['allow_pos_price_override' => true]);
    $ctx['vendor']->update(['pos_allow_price_change' => true]);
    $floor = app(PosPriceFloor::class);

    // Floor for a crate is its cost, ₦8,000, so half a crate may go to ₦4,000.
    $ok = resolveOne($ctx, line($ctx, 0.5, 'retail', 8000));
    expect(fn () => $floor->guard($ctx['vendor']->fresh(), [$ok], $ok['line_gross']))->not->toThrow(ValidationException::class);

    $tooLow = resolveOne($ctx, line($ctx, 0.5, 'retail', 8000) + ['half_unit_price' => 3900]);
    expect(fn () => $floor->guard($ctx['vendor']->fresh(), [$tooLow], $tooLow['line_gross']))->toThrow(ValidationException::class);
});

// ─── Turning the switch off ──────────────────────────────────────────────

it('refuses to turn halves off while a product still holds a half, naming it', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19.5]);

    try {
        $ctx['vendor']->fresh()->update(['allow_half_quantities' => false]);
        $this->fail('Turning halves off should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['allow_half_quantities'][0])->toContain('Hero Lager');
    }

    expect($ctx['vendor']->fresh()->allow_half_quantities)->toBeTrue()
        ->and($ctx['vendor']->fresh()->half_quantities_disabled_at)->toBeNull();
});

it('lets halves go off once stock is whole, and remembers that it was on', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19]);

    $ctx['vendor']->fresh()->update(['allow_half_quantities' => false]);

    $vendor = $ctx['vendor']->fresh();
    expect($vendor->allow_half_quantities)->toBeFalse()
        ->and($vendor->half_quantities_disabled_at)->not->toBeNull()
        ->and(Quantity::acceptsHalvesOnSync($vendor))->toBeTrue()
        ->and(Quantity::allowsHalves($vendor))->toBeFalse();
});

it('does not stamp a shop that never sold halves', function () {
    $ctx = halvesShop(false);

    $ctx['vendor']->fresh()->update(['name' => 'Renamed']);

    expect($ctx['vendor']->fresh()->half_quantities_disabled_at)->toBeNull()
        ->and(Quantity::acceptsHalvesOnSync($ctx['vendor']->fresh()))->toBeFalse();
});

// ─── Offline sync after the switch went off ──────────────────────────────

it('accepts a queued half on sync after halves went off, but not on a live sale', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19]);
    $ctx['vendor']->fresh()->update(['allow_half_quantities' => false]);
    $vendor = $ctx['vendor']->fresh();

    $synced = resolveOne($ctx, line($ctx, 0.5), Quantity::acceptsHalvesOnSync($vendor));
    expect($synced['line_gross'])->toBe(5050.0);

    // The live path uses the switch as it is now.
    expect(fn () => resolveOne($ctx, line($ctx, 0.5)))->toThrow(ValidationException::class);
});

// ─── Rolling back ────────────────────────────────────────────────────────

it('refuses to roll the stock columns back to integers while a half is held', function () {
    $ctx = halvesShop();
    shelf($ctx['product'])->update(['quantity' => 19.5]);

    $migration = require database_path('migrations/2026_10_14_200003_convert_stock_quantities_to_decimal.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'product_store_stock.quantity');
});
