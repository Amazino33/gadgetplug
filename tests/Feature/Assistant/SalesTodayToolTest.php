<?php

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Tools\ToolRegistry;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/** Runs sales_today through the registry — the guarded path the model uses. */
function salesToday(AssistantContext $context, array $input = []): array
{
    $run = app(ToolRegistry::class)->run('sales_today', $input, $context);

    return json_decode($run['content'], true) + ['_status' => $run['status']];
}

/**
 * Today's bounds exactly as HomeDashboard builds them — Lagos-zoned and not
 * converted to UTC — so the parity tests compare against the real dashboard
 * call, not a corrected copy of it.
 */
function lagosToday(): array
{
    $now = CarbonImmutable::now('Africa/Lagos');

    return [$now->startOfDay(), $now->endOfDay()];
}

/**
 * The parity tests run at midday Lagos. Between 00:00 and 01:00 Lagos the
 * dashboard's own window is an hour off (its bounds are never converted to
 * UTC — see PROGRESS.md, Findings), and comparing against it then would fail
 * the assistant for being right.
 */
function atLagosMidday(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
}

test('the whole-business total is the dashboard All Stores figure, to the naira', function () {
    atLagosMidday();
    $ctx = assistantVendor();
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 12000, 2);
    assistantSale($ctx['vendor'], $ctx['branch'], $ctx['product'], $ctx['owner'], 9500, 1);

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    [$from, $to] = lagosToday();
    $dashboard = app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to, null);

    expect($result['_status'])->toBe('ok')
        ->and($result['totals']['revenue'])->toEqual(round($dashboard['revenue'], 2))
        ->and($result['totals']['revenue'])->toEqual(33500.0)
        ->and($result['totals']['revenue_display'])->toBe('₦33,500')
        ->and($result['totals']['profit'])->toEqual(round($dashboard['profit'], 2))
        ->and($result['totals']['sales_count'])->toBe(2)
        ->and($result['scope'])->toBe('Whole business — all 2 stores')
        ->and($result['period']['label'])->toBe('Today')
        ->and($result['period']['timezone'])->toBe('Africa/Lagos');
});

test('one store reports the dashboard figure for that store only', function () {
    atLagosMidday();
    $ctx = assistantVendor();
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 12000, 2);
    assistantSale($ctx['vendor'], $ctx['branch'], $ctx['product'], $ctx['owner'], 9500, 1);

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']), ['store' => 'ikeja branch']);

    [$from, $to] = lagosToday();
    $dashboard = app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to, $ctx['branch']->id);

    expect($result['totals']['revenue'])->toEqual(round($dashboard['revenue'], 2))
        ->and($result['totals']['revenue'])->toEqual(9500.0)
        ->and($result['scope'])->toBe('Ikeja Branch')
        ->and($result['by_store'])->toBe([]);
});

test('the per-store rows add up to the whole-business total', function () {
    $ctx = assistantVendor();
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 4000, 3);
    assistantSale($ctx['vendor'], $ctx['branch'], $ctx['product'], $ctx['owner'], 2500, 2);

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    expect(collect($result['by_store'])->sum('revenue'))->toEqual($result['totals']['revenue'])
        ->and(collect($result['by_store'])->pluck('store')->all())->toContain('Ikeja Branch');
});

test('today is the Lagos day: a sale at 00:30 Lagos counts, one at 23:30 Lagos the night before does not', function () {
    // 10:00 Lagos on 6 Nov.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'Africa/Lagos'));

    $ctx = assistantVendor();
    // 00:30 Lagos today is 23:30 UTC yesterday — the hour a UTC "today" would lose.
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 1000, 1,
        CarbonImmutable::parse('2026-11-06 00:30', 'Africa/Lagos')->utc());
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 5000, 1,
        CarbonImmutable::parse('2026-11-05 23:30', 'Africa/Lagos')->utc());

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    expect($result['totals']['revenue'])->toEqual(1000.0)
        ->and($result['period']['from'])->toBe('Fri 6 Nov 2026, 00:00');

    CarbonImmutable::setTestNow();
});

test('a quiet day reports zero plainly rather than failing', function () {
    $ctx = assistantVendor();

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    expect($result['_status'])->toBe('ok')
        ->and($result['totals']['revenue'])->toEqual(0.0)
        ->and($result['totals']['revenue_display'])->toBe('₦0')
        ->and($result['totals']['sales_count'])->toBe(0);
});

test('the result carries a link to the Sales Report for checking', function () {
    $ctx = assistantVendor();

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    expect($result['panel_link'] ?? null)->toContain('/plug/'.$ctx['vendor']->slug);
});

test('a till user without report access gets only their own sales, labelled as such', function () {
    $ctx = assistantVendor();
    $ada = assistantStaff($ctx['vendor'], 'storekeeper', [$ctx['main']]);
    $bola = assistantStaff($ctx['vendor'], 'storekeeper', [$ctx['main']], [], 'Bola');

    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ada, 3000, 1);
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $bola, 50000, 1);
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 70000, 1);

    $result = salesToday(AssistantContext::for($ctx['vendor'], $ada));

    expect($result['_status'])->toBe('ok')
        ->and($result['totals']['revenue'])->toEqual(3000.0)
        ->and($result['totals']['sales_count'])->toBe(1)
        ->and($result['scope'])->toContain('Your own sales only')
        ->and(json_encode($result))->not->toContain('50,000')
        ->and(json_encode($result))->not->toContain('70,000');
});
