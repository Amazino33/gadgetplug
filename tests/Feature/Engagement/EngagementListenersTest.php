<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\Moments\CashSalesTodayReached;
use App\Support\Engagement\Moments\FirstProductAdded;
use App\Support\Engagement\Moments\MomentRecorder;
use App\Support\Engagement\Moments\PendingMoments;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;

/**
 * The engagement listeners sit on the POS sale, product and usage-source
 * saves. These tests hold them to the rules that make that safe: they never
 * fail a save, they ignore offline sales from earlier days, they do their
 * work once per bulk operation rather than once per row, and they leave a
 * cached first-use date alone.
 */

/** A sync body of $n cash sales, all completed at $completedAt. */
function listenerSyncBody(array $ctx, int $n, mixed $completedAt = null): array
{
    return [
        'vendor_id' => $ctx['vendor']->id,
        'sales' => collect(range(1, $n))->map(fn (int $i) => array_merge(debtSalePayload($ctx, [
            'customer_id' => null,
            'payment_method' => 'cash',
            'amount_tendered' => 10000,
            'total' => 10000,
        ]), [
            'offline_id' => 'listener-'.uniqid().'-'.$i,
            'completed_at' => CarbonImmutable::parse($completedAt ?? now())->toIso8601String(),
        ]))->all(),
    ];
}

function waitForCashSalesMoment(): void
{
    makeTip(['moment' => CashSalesTodayReached::KEY, 'moment_threshold' => 5]);
}

function cashSalesMomentFor(array $ctx): ?int
{
    return app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id)[CashSalesTodayReached::KEY] ?? null;
}

// ── 1. Sale safety ───────────────────────────────────────────────────────────

it('still saves and syncs the sale when the moment listener throws', function (string $when) {
    Exceptions::fake();
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    app()->instance(MomentRecorder::class, new class($when)
    {
        public function __construct(private string $when) {}

        public function saleSaved($sale): ?callable
        {
            if ($this->when === 'during the save') {
                throw new RuntimeException('moment listener broke during the save');
            }

            return fn () => throw new RuntimeException('moment listener broke after commit');
        }

        public function productCreated($product): ?callable
        {
            return null;
        }
    });

    $response = $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 2))->assertOk();

    expect($response->json('results.*.status'))->toBe(['synced', 'synced'])
        ->and(PosSale::count())->toBe(2);

    Exceptions::assertReported(RuntimeException::class);
})->with(['during the save', 'after commit']);

it('still saves the sale when the usage recheck throws', function () {
    Exceptions::fake();
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    app()->instance(FeatureUsage::class, new class(app(FeatureRegistry::class)) extends FeatureUsage
    {
        public function recheck(array $keys): void
        {
            throw new RuntimeException('usage recheck broke');
        }
    });

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 1))->assertOk();

    expect(PosSale::count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'usage recheck broke');
});

// ── 2. Late offline sync ─────────────────────────────────────────────────────

it("counts today's cash sales by their own sale date, not the day they synced", function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'UTC')); // 11:00 Lagos, 11 Nov
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);
    waitForCashSalesMoment();

    // Rung up offline yesterday, synced this morning: not today's sales.
    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 6, '2026-11-10 15:00:00'))->assertOk();
    expect(PosSale::count())->toBe(6)
        ->and(cashSalesMomentFor($ctx))->toBeNull();

    // Two from today in the same kind of sync: those count, and only those.
    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 2, '2026-11-11 09:00:00'))->assertOk();
    expect(cashSalesMomentFor($ctx))->toBe(2);
});

it('uses the Lagos day, so a sale at 00:30 Lagos is today and one at 23:30 Lagos is yesterday', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);
    waitForCashSalesMoment();

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 1, '2026-11-10 22:30:00'))->assertOk(); // 23:30 Lagos, 10 Nov
    expect(cashSalesMomentFor($ctx))->toBeNull();

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 1, '2026-11-10 23:30:00'))->assertOk(); // 00:30 Lagos, 11 Nov
    expect(cashSalesMomentFor($ctx))->toBe(1);
});

it('counts a whole sync once, and ignores credit sales', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);
    waitForCashSalesMoment();

    $counts = 0;
    DB::listen(function ($query) use (&$counts) {
        if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"pos_sales"') && str_contains($query->sql, '"cashier_id"')) {
            $counts++;
        }
    });

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 7))->assertOk();

    expect(cashSalesMomentFor($ctx))->toBe(7)
        ->and($counts)->toBe(1);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertCreated(); // credit

    expect(cashSalesMomentFor($ctx))->toBe(7);
});

it('does no moment work at all when no live tip waits for the moment', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 6))->assertOk();

    expect(cashSalesMomentFor($ctx))->toBeNull();
});

// ── 3. Bulk import ───────────────────────────────────────────────────────────

/** Create $n products the way ProductImporter does: signed in, in transactions of 50. */
function importTestProducts(array $ctx, int $n): void
{
    $category = Category::create(['name' => 'Import '.uniqid()]);

    foreach (array_chunk(range(1, $n), 50) as $chunk) {
        DB::transaction(function () use ($ctx, $category, $chunk) {
            foreach ($chunk as $i) {
                Product::create([
                    'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'category_id' => $category->id,
                    'name' => "Imported {$i}", 'price' => 100, 'cost_price' => 50, 'stock_quantity' => 1,
                    'reserved_stock' => 0, 'status' => 'published',
                ]);
            }
        });
    }
}

it('fires first_product_added once for a 500-row import, with one check for the whole import', function () {
    $ctx = engagementVendor();
    makeTip(['moment' => FirstProductAdded::KEY]);
    $this->actingAs($ctx['owner']);

    $firstChecks = 0;
    $tipLookups = 0;
    DB::listen(function ($query) use (&$firstChecks, &$tipLookups) {
        if (str_contains($query->sql, 'from "products"') && str_contains($query->sql, '"products"."id" !=')) {
            $firstChecks++;
        }
        if (str_contains($query->sql, 'from "tips"')) {
            $tipLookups++;
        }
    });

    importTestProducts($ctx, 500);

    expect(Product::where('vendor_id', $ctx['vendor']->id)->count())->toBe(500)
        ->and(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))->toBe([FirstProductAdded::KEY => 1])
        ->and($firstChecks)->toBe(1)
        ->and($tipLookups)->toBe(1);
});

it('does not fire first_product_added for a business that already had products', function () {
    $ctx = engagementVendor();
    makeTip(['moment' => FirstProductAdded::KEY]);
    $this->actingAs($ctx['owner']);

    importTestProducts($ctx, 1);
    app()->forgetScopedInstances(); // a later request
    app(PendingMoments::class)->forget($ctx['owner']->id, $ctx['vendor']->id);

    importTestProducts($ctx, 60);

    expect(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))->toBe([]);
});

it('fires nothing for a product saved with nobody signed in', function () {
    $ctx = engagementVendor();
    makeTip(['moment' => FirstProductAdded::KEY]);

    importTestProducts($ctx, 3);

    expect(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))->toBe([]);
});

// ── 4. Cache refresh ─────────────────────────────────────────────────────────

/** @return array{written: array, forgotten: array, read: array} engagement first-use cache traffic */
function watchFirstUseCache(): object
{
    $log = (object) ['written' => [], 'forgotten' => [], 'read' => []];
    $mine = fn (string $key) => str_starts_with($key, 'engagement:first-use:');

    Event::listen(KeyWritten::class, function ($e) use ($log, $mine) {
        if ($mine($e->key)) {
            $log->written[] = $e->key;
        }
    });
    Event::listen(KeyForgotten::class, function ($e) use ($log, $mine) {
        if ($mine($e->key)) {
            $log->forgotten[] = $e->key;
        }
    });
    foreach ([CacheHit::class, CacheMissed::class] as $read) {
        Event::listen($read, function ($e) use ($log, $mine) {
            if ($mine($e->key)) {
                $log->read[] = $e->key;
            }
        });
    }

    return $log;
}

it('leaves a cached first-use date alone through a big offline sync', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);
    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 1))->assertOk();

    // The owner opens a page: every answer is now cached, Sales Point as a date.
    $salesPoint = app(FeatureRegistry::class)->find('sales_point');
    expect(app(FeatureUsage::class)->hasUsed($salesPoint, $ctx['owner'], $ctx['vendor']))->toBeTrue();

    app()->forgetScopedInstances(); // the sync is a new request
    $cache = watchFirstUseCache();

    $this->postJson('/api/pos/sync', listenerSyncBody($ctx, 40))->assertOk();

    expect(PosSale::count())->toBe(41)
        ->and($cache->written)->toBe([])
        ->and($cache->forgotten)->toBe([])
        // One batched look at the two keys a sale can affect (the business's
        // and the cashier's), then nothing for the other 39 sales.
        ->and(count($cache->read))->toBeLessThanOrEqual(2)
        ->and(Cache::get(FeatureUsage::cacheKey('sales_point', $ctx['vendor']->id, null)))->not->toBe(FeatureUsage::NEVER);
});

it('clears a cached "not used yet" only after the transaction commits', function () {
    $ctx = engagementVendor();
    $debts = app(FeatureRegistry::class)->find('customer_debts');
    $key = FeatureUsage::cacheKey('customer_debts', $ctx['vendor']->id, null);

    expect(app(FeatureUsage::class)->hasUsed($debts, $ctx['owner'], $ctx['vendor']))->toBeFalse()
        ->and(Cache::get($key))->toBe(FeatureUsage::NEVER);

    DB::transaction(function () use ($ctx, $key) {
        recordTestDebt($ctx, $ctx['storekeeper']);

        // Not yet committed: nothing has been cleared.
        expect(Cache::get($key))->toBe(FeatureUsage::NEVER);
    });

    expect(Cache::get($key))->toBeNull()
        ->and(app(FeatureUsage::class)->hasUsed($debts, $ctx['owner'], $ctx['vendor']))->toBeTrue();
});

it('clears the person-level answer too, for the person the row names', function () {
    $ctx = engagementVendor();
    $debts = app(FeatureRegistry::class)->find('customer_debts');

    expect(app(FeatureUsage::class)->hasUsed($debts, $ctx['storekeeper'], $ctx['vendor']))->toBeFalse();

    recordTestDebt($ctx, $ctx['storekeeper']);

    expect(app(FeatureUsage::class)->hasUsed($debts, $ctx['storekeeper'], $ctx['vendor']))->toBeTrue();
});
