<?php

use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\StockCostLayer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\SalesReportService;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

// Phase 3 of half quantities: the agreed acceptance list, end to end through
// the real endpoints. The regression half pins down that a shop with the
// switch off — a supermarket — sees exactly what it always did.

function acceptShop(bool $halves = true, array $product = [], array $vendor = []): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(array_merge([
        'user_id'                => $owner->id,
        'name'                   => ($halves ? 'Veruzo Wholesale ' : 'Corner Mart ').uniqid(),
        'pos_min_margin_percent' => 0,
        'allow_half_quantities'  => $halves,
    ], $vendor));
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

    // One batch at the crate cost, so cost of goods sold is real, not estimated.
    StockCostLayer::where('product_id', $product->id)->delete();
    StockCostLayer::create([
        'product_id' => $product->id, 'store_id' => ProductStoreStock::where('product_id', $product->id)->value('store_id'),
        'unit_cost' => 8000, 'quantity_received' => 20, 'quantity_remaining' => 20, 'received_at' => now()->subDay(),
    ]);

    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    return compact('owner', 'vendor', 'product', 'base');
}

function acceptSale(array $ctx, int|float $qty, array $line = [])
{
    Sanctum::actingAs($ctx['owner']);

    $type  = $line['price_type'] ?? 'retail';
    $price = $line['unit_price'] ?? ($type === 'wholesale' ? 9600 : 10100);

    return test()->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => [array_merge([
            'product_id'      => $ctx['product']->id,
            'product_name'    => 'Hero Lager',
            'unit_price'      => $price,
            'quantity'        => $qty,
            'product_unit_id' => $ctx['base']->id,
            'unit_qty'        => $qty,
            'price_type'      => $type,
        ], $line)],
        'payment_method'  => 'cash',
        'amount_tendered' => 100000,
        'vat_rate'        => 0,
    ]);
}

function acceptSync(array $ctx, string $offlineId, int|float $qty)
{
    Sanctum::actingAs($ctx['owner']);

    return test()->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'      => $offlineId,
            'items'           => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Hero Lager',
                'unit_price' => 10100, 'quantity' => $qty, 'total' => 10100 * $qty,
                'product_unit_id' => $ctx['base']->id, 'unit_qty' => $qty,
            ]],
            'total'           => 10100 * $qty,
            'vat_amount'      => 0,
            'payment_method'  => 'cash',
            'amount_tendered' => 100000,
            'completed_at'    => now()->subMinutes(10)->toDateTimeString(),
            'store_id'        => $ctx['vendor']->defaultStore?->id,
            'payments'        => null,
        ]],
    ]);
}

function acceptPanel(array $ctx): void
{
    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);
}

// ═══ Regression: a shop with the switch off ═════════════════════════════

describe('a supermarket, with half quantities off', function () {
    it('is refused half a unit online and offline, and nothing leaves the shelf', function () {
        $ctx = acceptShop(false);

        acceptSale($ctx, 0.5)->assertUnprocessable();
        acceptSync($ctx, 'mart-half', 0.5)->assertSuccessful()->assertJsonPath('results.0.status', 'rejected');

        expect($ctx['product']->fresh()->stock_quantity)->toBe(20)
            ->and(PosSale::count())->toBe(0);
    });

    it('sells, deducts and costs whole units exactly as before', function () {
        $ctx = acceptShop(false);

        acceptSale($ctx, 2)->assertSuccessful();

        $line = PosSaleItem::sole();

        // Ints, not "2.000" — the same types every reader always had.
        expect($line->quantity)->toBe(2)
            ->and($line->unit_qty)->toBe(2)
            ->and((float) $line->total)->toBe(20200.0)
            ->and((float) $line->cost_total)->toBe(16000.0)
            ->and($line->half_unit_price)->toBeNull()
            ->and($ctx['product']->fresh()->stock_quantity)->toBe(18)
            ->and(ProductStoreStock::where('product_id', $ctx['product']->id)->value('quantity'))->toBe(18);
    });

    it('prints a whole line on the receipt exactly as before', function () {
        $ctx = acceptShop(false);
        acceptSale($ctx, 2)->assertSuccessful();

        expect(PosSaleItem::sole()->receiptLine())->toBe([
            'quantity'   => 2,
            'unit_price' => PosSaleItem::sole()->unit_price,
            'half_price' => null,
        ]);
    });

    it('reports whole units as ints and the same revenue, cost and profit', function () {
        $ctx = acceptShop(false);
        acceptSale($ctx, 2)->assertSuccessful();

        $summary = app(SalesReportService::class)->summary($ctx['vendor']->id, now()->subDay(), now()->addDay());
        $top     = app(SalesReportService::class)->topProducts($ctx['vendor']->id, now()->subDay(), now()->addDay());

        expect((float) $summary['revenue'])->toBe(20200.0)
            ->and((float) $summary['cost'])->toBe(16000.0)
            ->and((float) $summary['profit'])->toBe(4200.0)
            ->and($top->first()['units'] ?? null)->toBe(2);
    });

    it('shows no half price field on the product form', function () {
        $ctx = acceptShop(false);
        acceptPanel($ctx);

        Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
            ->assertFormFieldDoesNotExist('half_price');
    });

    it('leaves a half price saved earlier untouched when the form is saved', function () {
        $ctx = acceptShop(false, ['half_price' => 5200]);
        acceptPanel($ctx);

        Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($ctx['product']->fresh()->half_price)->toBe('5200.00');
    });
});

// ═══ A shop that sells halves ═══════════════════════════════════════════

describe('a halves shop', function () {
    it('shows the half price field on the product form', function () {
        $ctx = acceptShop();
        acceptPanel($ctx);

        Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
            ->assertFormFieldExists('half_price');
    });

    it('sells ½ crate at ₦5,050 and takes 20 down to 19½', function () {
        $ctx = acceptShop();

        acceptSale($ctx, 0.5)->assertSuccessful();

        expect((float) PosSaleItem::sole()->total)->toBe(5050.0)
            ->and($ctx['product']->fresh()->stock_quantity)->toBe(19.5);
    });

    it('sells ½ at the set half price of ₦5,200 and 1½ at ₦15,300', function () {
        $ctx = acceptShop(true, ['half_price' => 5200]);

        acceptSale($ctx, 0.5)->assertSuccessful();
        acceptSale($ctx, 1.5)->assertSuccessful();

        expect(PosSaleItem::orderBy('id')->pluck('total')->map(fn ($t) => (float) $t)->all())->toBe([5200.0, 15300.0]);
    });

    it('halves the wholesale price: crate ₦9,600, half ₦4,800', function () {
        $ctx = acceptShop(true, ['half_price' => 5200]);

        acceptSale($ctx, 0.5, ['price_type' => 'wholesale'])->assertSuccessful();

        expect((float) PosSaleItem::sole()->total)->toBe(4800.0);
    });

    it('refuses 0.3 and 0', function (float $qty) {
        $ctx = acceptShop();

        acceptSale($ctx, $qty)->assertUnprocessable();

        expect($ctx['product']->fresh()->stock_quantity)->toBe(20);
    })->with([0.3, 0.0]);

    it('costs half a crate at half the crate cost, and the report agrees', function () {
        $ctx = acceptShop(true, ['half_price' => 5200]);

        acceptSale($ctx, 0.5)->assertSuccessful();

        $line    = PosSaleItem::sole();
        $summary = app(SalesReportService::class)->summary($ctx['vendor']->id, now()->subDay(), now()->addDay());

        expect((float) $line->cost_total)->toBe(4000.0)
            ->and((float) $summary['revenue'])->toBe(5200.0)
            ->and((float) $summary['cost'])->toBe(4000.0)
            ->and((float) $summary['profit'])->toBe(1200.0);
    });

    it('enforces the floor on a haggled half line on the server', function () {
        // Price changes on; the floor for a crate is its cost, ₦8,000, so half
        // a crate may go no lower than ₦4,000.
        $ctx = acceptShop(true, ['allow_pos_price_override' => true], ['pos_allow_price_change' => true]);

        acceptSale($ctx, 0.5, ['unit_price' => 8000, 'half_unit_price' => 3900])->assertUnprocessable();
        acceptSale($ctx, 0.5, ['unit_price' => 8000, 'half_unit_price' => 4000])->assertSuccessful();

        expect((float) PosSaleItem::sole()->total)->toBe(4000.0);
    });

    it('syncs a half sale queued offline', function () {
        $ctx = acceptShop();

        acceptSync($ctx, 'veruzo-half', 1.5)
            ->assertSuccessful()
            ->assertJsonPath('results.0.status', 'synced');

        expect((float) PosSaleItem::sole()->total)->toBe(15150.0)
            ->and($ctx['product']->fresh()->stock_quantity)->toBe(18.5);
    });

    it('prints "1½ Crate" with the half price on the receipt', function () {
        $ctx = acceptShop(true, ['half_price' => 5200]);
        acceptSale($ctx, 1.5)->assertSuccessful();

        expect(PosSaleItem::sole()->receiptLine())->toBe([
            'quantity'   => '1½ Crate',
            'unit_price' => 10100.0,
            'half_price' => 5200.0,
        ]);
    });
});
