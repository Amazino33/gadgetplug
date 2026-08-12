<?php

use App\Models\Affiliate;
use App\Models\AffiliatePointConversion;
use App\Models\AffiliateSetting;
use App\Models\PlugPointTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\PlugPointService;
use App\Services\Affiliate\PointConversionService;
use App\Services\Affiliate\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function conversionAffiliate(int $points = 0): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($points !== 0) {
        $affiliate->plugPointTransactions()->create([
            'type'        => 'credit',
            'points'      => $points,
            'source'      => 'adjustment',
            'description' => 'Test seed',
        ]);
    }

    return $affiliate;
}

test('converting debits points and credits the wallet at the configured rate', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 0.5, 'min_points_conversion' => 100]);

    $affiliate = conversionAffiliate(1000);

    $conversion = app(PointConversionService::class)->convert($affiliate, 400, 'key-1');

    expect((float) $conversion->amount)->toBe(200.0)
        ->and((float) $conversion->naira_per_point)->toBe(0.5)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(600)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(200.0);
});

test('the wallet rises by exactly the converted amount and by nothing else', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 2.0, 'min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(500);

    app(PointConversionService::class)->convert($affiliate, 250, 'key-1');

    $credits = WalletTransaction::where('affiliate_id', $affiliate->id)->get();

    expect($credits)->toHaveCount(1)
        ->and($credits->first()->type)->toBe('credit')
        ->and((float) $credits->first()->amount)->toBe(500.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(500.0);
});

test('a conversion below the minimum threshold is refused and moves nothing', function () {
    AffiliateSetting::current()->update(['min_points_conversion' => 1000]);

    $affiliate = conversionAffiliate(5000);

    expect(fn () => app(PointConversionService::class)->convert($affiliate, 999, 'key-1'))
        ->toThrow(RuntimeException::class);

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(5000)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0)
        ->and(AffiliatePointConversion::count())->toBe(0);
});

test('an affiliate cannot overdraw their points balance', function () {
    AffiliateSetting::current()->update(['min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(300);

    expect(fn () => app(PointConversionService::class)->convert($affiliate, 500, 'key-1'))
        ->toThrow(RuntimeException::class);

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(300)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);
});

test('a repeated conversion with the same idempotency key spends the points once', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 1.0, 'min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(1000);
    $service   = app(PointConversionService::class);

    $first  = $service->convert($affiliate, 200, 'double-click');
    $second = $service->convert($affiliate, 200, 'double-click');

    expect($second->id)->toBe($first->id)
        ->and(AffiliatePointConversion::count())->toBe(1)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(800)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(200.0);
});

test('two different conversions both land, each with its own key', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 1.0, 'min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(1000);
    $service   = app(PointConversionService::class);

    $service->convert($affiliate, 200, 'key-a');
    $service->convert($affiliate, 300, 'key-b');

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(500)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(500.0);
});

test('a failed conversion is atomic — no orphan debit, no orphan credit', function () {
    AffiliateSetting::current()->update(['min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(100);

    try {
        app(PointConversionService::class)->convert($affiliate, 999, 'key-1');
    } catch (RuntimeException) {
        // expected
    }

    expect(PlugPointTransaction::where('source', 'conversion')->count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0)
        ->and(AffiliatePointConversion::count())->toBe(0);
});

test('changing the rate later never restates an already-converted amount', function () {
    AffiliateSetting::current()->update(['naira_per_point' => 1.0, 'min_points_conversion' => 10]);

    $affiliate = conversionAffiliate(1000);
    $conversion = app(PointConversionService::class)->convert($affiliate, 100, 'key-1');

    AffiliateSetting::current()->update(['naira_per_point' => 99.0]);

    expect((float) $conversion->fresh()->naira_per_point)->toBe(1.0)
        ->and((float) $conversion->fresh()->amount)->toBe(100.0)
        ->and(app(WalletService::class)->availableBalance($affiliate->id))->toBe(100.0);
});

test('the points ledger is append-only', function () {
    $affiliate = conversionAffiliate(100);
    $row = PlugPointTransaction::where('affiliate_id', $affiliate->id)->first();

    expect(fn () => $row->update(['points' => 999]))->toThrow(LogicException::class);
});
