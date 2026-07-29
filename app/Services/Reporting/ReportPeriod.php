<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Turns the dashboard's filter state into a concrete from/to pair, in one place,
// so every widget on the page reports on exactly the same window.
//
// Boundaries are worked out on the store's wall clock (see config/reporting.php)
// and only then converted to the app timezone for querying — otherwise "today"
// would start an hour late for a Nigerian store and the first hour of each day's
// takings would land under yesterday.
final class ReportPeriod
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_7' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'custom' => 'Custom range',
    ];

    public const DEFAULT_PRESET = 'this_month';

    public function __construct(
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly string $label,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $preset = $filters['preset'] ?? self::DEFAULT_PRESET;
        $now = CarbonImmutable::now(config('reporting.timezone'));

        [$from, $to] = match ($preset) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfDay()],
            'last_7' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfDay()],
            'custom' => self::customRange($filters, $now),
            default => [$now->startOfMonth(), $now->endOfDay()],
        };

        return new self(
            self::toAppTimezone($from),
            self::toAppTimezone($to),
            self::PRESETS[$preset] ?? self::PRESETS[self::DEFAULT_PRESET],
        );
    }

    /**
     * The equally-long window immediately before this one, for "vs previous"
     * comparisons. A 7-day range compares against the 7 days before it.
     */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to);

        return new self(
            $this->from->subSeconds($length),
            $this->from->subSecond(),
            'Previous period',
        );
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private static function customRange(?array $filters, CarbonImmutable $now): array
    {
        $tz = config('reporting.timezone');

        // Falls back to the month so a half-filled custom range still renders
        // something sane rather than an empty or inverted window.
        $from = filled($filters['from'] ?? null)
            ? CarbonImmutable::parse($filters['from'], $tz)->startOfDay()
            : $now->startOfMonth();

        $to = filled($filters['to'] ?? null)
            ? CarbonImmutable::parse($filters['to'], $tz)->endOfDay()
            : $now->endOfDay();

        return $from->greaterThan($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }

    private static function toAppTimezone(CarbonInterface $date): CarbonInterface
    {
        return $date->setTimezone(config('app.timezone', 'UTC'));
    }
}
