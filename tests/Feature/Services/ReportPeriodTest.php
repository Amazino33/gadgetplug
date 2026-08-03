<?php

use App\Services\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;

test('the default preset is today, not this month', function () {
    expect(ReportPeriod::DEFAULT_PRESET)->toBe('today');
});

test('fromFilters with no preset defaults to a window that contains right now', function () {
    $period = ReportPeriod::fromFilters(null);

    // The boundaries are converted to the app's storage timezone (see
    // toAppTimezone()) — comparing formatted dates directly can legitimately
    // differ from the store's own calendar date near a timezone offset.
    // What actually matters: a sale happening right now must fall inside
    // "today"'s window, since that's what the report query will compare against.
    $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

    expect($now->betweenIncluded($period->from, $period->to))->toBeTrue()
        ->and($period->to->diffInHours($period->from))->toBeLessThanOrEqual(24)
        ->and($period->label)->toBe('Today');
});
