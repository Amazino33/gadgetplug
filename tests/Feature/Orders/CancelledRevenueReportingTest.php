<?php

use App\Services\Reporting\FinancialReportService;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\SalesReportService;
use App\Services\Reporting\StoreSalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

// An order cancelled after its revenue was recorded:
//  - the Financial Report follows the ledger — revenue stays in the period it
//    was recorded, the reversal lands as a negative in the period of the
//    cancellation, and a past period never changes;
//  - the "what sold" figures leave it out entirely.

function monthReport(array $c, CarbonImmutable $month): array
{
    return app(FinancialReportService::class)->report($c['vendor']->id, $month->startOfMonth(), $month->endOfMonth());
}

it('nets to nothing when an order is paid and cancelled in the same month', function () {
    $c = ossVendor();
    $march = CarbonImmutable::parse('2026-03-10 10:00');

    $this->travelTo($march);
    $order = ossOrder($c, 'paystack', 'paid', 2);
    $this->travelTo($march->addDays(5));
    oss()->cancel($order, $c['admin'], 'Refunded');
    $this->travelBack();

    $report = monthReport($c, $march);

    expect($report['revenue'])->toEqualWithDelta(0.0, 0.01)
        ->and($report['product_cost'])->toEqualWithDelta(0.0, 0.01)
        ->and($report['cancelled_online_revenue'])->toEqualWithDelta(10000.0, 0.01);
});

it('keeps a past month as it was and reverses in the month of the cancellation', function () {
    $c = ossVendor();
    $march = CarbonImmutable::parse('2026-03-20 10:00');
    $april = CarbonImmutable::parse('2026-04-03 10:00');

    $this->travelTo($march);
    $order = ossOrder($c, 'paystack', 'paid', 2);
    $this->travelBack();

    $marchBefore = monthReport($c, $march);

    $this->travelTo($april);
    oss()->cancel($order, $c['admin'], 'Refunded');
    $this->travelBack();

    $marchAfter = monthReport($c, $march);
    $aprilReport = monthReport($c, $april);
    $both = app(FinancialReportService::class)->report($c['vendor']->id, $march->startOfMonth(), $april->endOfMonth());

    expect($marchBefore['revenue'])->toEqualWithDelta(10000.0, 0.01)
        // March is exactly what was read before the cancellation.
        ->and($marchAfter['revenue'])->toEqualWithDelta($marchBefore['revenue'], 0.01)
        ->and($marchAfter['product_cost'])->toEqualWithDelta($marchBefore['product_cost'], 0.01)
        ->and($marchAfter['net_profit'])->toEqualWithDelta($marchBefore['net_profit'], 0.01)
        // April carries the reversal as a negative.
        ->and($aprilReport['revenue'])->toEqualWithDelta(-10000.0, 0.01)
        ->and($aprilReport['product_cost'])->toEqualWithDelta(-$marchBefore['product_cost'], 0.01)
        // Across both months it is as if it never sold.
        ->and($both['revenue'])->toEqualWithDelta(0.0, 0.01)
        ->and($both['product_cost'])->toEqualWithDelta(0.0, 0.01);
});

it('dates the reversal by the ledger even for a cancellation with no cancelled_at', function () {
    $c = ossVendor();
    $march = CarbonImmutable::parse('2026-03-20 10:00');

    $this->travelTo($march);
    $order = ossOrder($c, 'paystack', 'paid', 1);
    $this->travelTo(CarbonImmutable::parse('2026-04-02 10:00'));
    oss()->cancel($order, $c['admin'], 'Refunded');
    $this->travelBack();

    // An order cancelled before the timeline column existed.
    $order->refresh()->updateQuietly(['cancelled_at' => null]);

    expect(monthReport($c, CarbonImmutable::parse('2026-04-15'))['revenue'])->toEqualWithDelta(-5000.0, 0.01)
        ->and(monthReport($c, $march)['revenue'])->toEqualWithDelta(5000.0, 0.01);
});

it('leaves a cancelled order out of every "what sold" figure', function () {
    $c = ossVendor();
    $kept = ossOrder($c, 'paystack', 'paid', 1);
    $cancelled = ossOrder($c, 'paystack', 'paid', 2);
    oss()->cancel($cancelled, $c['admin'], 'Refunded');

    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    $topSellers = app(ProductVelocityService::class)->topSellers($c['vendor']->id, $from, $to);

    expect(app(SalesReportService::class)->summary($c['vendor']->id, $from, $to)['revenue'])->toEqualWithDelta(5000.0, 0.01)
        ->and(StoreSalesQuery::totals($c['vendor']->id, null, $from, $to)['revenue'])->toEqualWithDelta(5000.0, 0.01)
        ->and(StoreSalesQuery::totals($c['vendor']->id, null, $from, $to)['orders'])->toBe(1)
        ->and(StoreSalesQuery::totals($c['vendor']->id, $c['main']->id, $from, $to)['units'])->toBe(1)
        ->and($topSellers->sum(fn ($row) => $row->unitsSold))->toBe(1);
});
