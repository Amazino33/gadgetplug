<?php

use App\Models\AppFeature;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\Moments\CashSalesTodayReached;
use App\Support\Engagement\Moments\FirstProductAdded;
use App\Support\Engagement\Moments\PendingMoments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

// ── The registry ─────────────────────────────────────────────────────────────

it('points every feature with a page at a route that exists', function () {
    foreach (app(FeatureRegistry::class)->all() as $key => $feature) {
        $route = $feature->destinationRoute();

        if ($route !== null) {
            expect(Route::has($route))->toBeTrue("{$key} points at missing route {$route}");
        }
    }
});

it('answers every feature without error for a brand new vendor', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Brand New '.uniqid()]);

    foreach (app(FeatureRegistry::class)->all() as $key => $feature) {
        expect($feature->firstUsedAt($vendor->id))->toBeNull("{$key} is already used by a new vendor")
            ->and($feature->firstUsedAt($vendor->id, $owner->id))->toBeNull()
            ->and($feature->firstUseByVendor())->toBe([]);
    }
});

it('only counts a second branch as multi-store, and only other people as staff', function () {
    $ctx = engagementVendor();
    $registry = app(FeatureRegistry::class);

    // The owner on vendor_users is not staff; the storekeeper attached by the
    // helper is.
    $ctx['vendor']->users()->attach($ctx['owner']->id);
    expect($registry->find('team_members')->firstUsedAt($ctx['vendor']->id))->not->toBeNull()
        ->and($registry->find('multi_store')->firstUsedAt($ctx['vendor']->id))->toBeNull();

    Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Branch Two']);

    expect($registry->find('multi_store')->firstUseByVendor([$ctx['vendor']->id]))->toHaveKey($ctx['vendor']->id);
});

it('reports which features can tell one person from another', function () {
    $registry = app(FeatureRegistry::class);

    expect($registry->find('customer_debts')->supportsUserLevel())->toBeTrue()
        ->and($registry->find('cash_submissions')->supportsUserLevel())->toBeTrue()
        ->and($registry->find('product_photos')->supportsUserLevel())->toBeFalse()
        ->and($registry->find('team_members')->supportsUserLevel())->toBeFalse()
        ->and($registry->find('multi_store')->supportsUserLevel())->toBeFalse();
});

it('refreshes cached usage as soon as a feature is used', function () {
    $ctx = engagementVendor();
    $usage = app(FeatureUsage::class);
    $debts = app(FeatureRegistry::class)->find('customer_debts');

    expect($usage->hasUsed($debts, $ctx['owner'], $ctx['vendor']))->toBeFalse();

    recordTestDebt($ctx, $ctx['owner']);

    expect($usage->hasUsed($debts, $ctx['owner'], $ctx['vendor']))->toBeTrue();
});

// ── features:sync ────────────────────────────────────────────────────────────

it('creates a row per feature, keeps admin edits and retires removed features', function () {
    AppFeature::create(['key' => 'retired_feature', 'title' => 'Gone']);

    $this->artisan('features:sync')->assertSuccessful();

    expect(AppFeature::where('is_registered', true)->count())->toBe(count(FeatureRegistry::FEATURES))
        ->and(AppFeature::where('key', 'retired_feature')->value('is_registered'))->toBeFalse();

    AppFeature::where('key', 'customer_debts')->update(['title' => 'Edited by admin', 'is_visible' => false]);

    $this->artisan('features:sync')->assertSuccessful();

    expect(AppFeature::where('key', 'customer_debts')->first())
        ->title->toBe('Edited by admin')
        ->is_visible->toBeFalse();
});

// ── Moment listeners ─────────────────────────────────────────────────────────

it("records the cash sales moment from a real POS sale, counting only today's cash sales", function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    foreach (range(1, 2) as $i) {
        $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
            'customer_id' => null, 'payment_method' => 'cash', 'amount_tendered' => 10000,
        ]))->assertCreated();
    }

    // A credit sale is not a cash sale.
    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertCreated();

    expect(PosSale::count())->toBe(3)
        ->and(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))
        ->toBe([CashSalesTodayReached::KEY => 2]);
});

it('keeps the cash sales moment until the end of the Lagos day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 21:00:00', 'UTC')); // 22:00 Lagos

    $ctx = engagementVendor();
    app(PendingMoments::class)->record($ctx['storekeeper']->id, $ctx['vendor']->id, CashSalesTodayReached::KEY, 5, app(CashSalesTodayReached::class)->expiresAt());

    $this->travelTo(CarbonImmutable::parse('2026-11-10 22:59:00', 'UTC')); // 23:59 Lagos
    expect(app(PendingMoments::class)->active($ctx['storekeeper']->id, $ctx['vendor']->id))->toHaveKey(CashSalesTodayReached::KEY);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 23:01:00', 'UTC')); // 00:01 Lagos, next day
    expect(app(PendingMoments::class)->active($ctx['storekeeper']->id, $ctx['vendor']->id))->toBe([]);
});

it('records the first product moment for the person who added it, and only for the first', function () {
    $ctx = engagementVendor();
    $this->actingAs($ctx['owner']);
    $category = Category::create(['name' => 'C'.uniqid()]);

    $make = fn (string $name) => Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'category_id' => $category->id,
        'name' => $name, 'price' => 100, 'cost_price' => 50, 'stock_quantity' => 1,
        'reserved_stock' => 0, 'status' => 'published',
    ]);

    $make('First');
    expect(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))->toBe([FirstProductAdded::KEY => 1]);

    app(PendingMoments::class)->forget($ctx['owner']->id, $ctx['vendor']->id);
    $make('Second');
    expect(app(PendingMoments::class)->active($ctx['owner']->id, $ctx['vendor']->id))->toBe([]);
});

// ── Tip events are a log ─────────────────────────────────────────────────────

it('refuses to edit or delete a tip event, or log an unknown one', function () {
    $ctx = engagementVendor();
    $tip = makeTip();
    $event = logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN);

    expect(fn () => $event->update(['event' => TipEvent::CLICKED]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => logTipEvent($tip, $ctx['owner'], $ctx['vendor'], 'liked'))->toThrow(LogicException::class);
});

it('defaults announcements to the centre modal and tips to the bottom sheet', function () {
    expect(makeTip()->display_style)->toBe(Tip::STYLE_SHEET)
        ->and(makeTip(['type' => Tip::TYPE_ANNOUNCEMENT])->display_style)->toBe(Tip::STYLE_MODAL)
        ->and(makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'display_style' => Tip::STYLE_SHEET])->display_style)->toBe(Tip::STYLE_SHEET);
});
