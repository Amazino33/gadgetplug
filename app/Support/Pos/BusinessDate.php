<?php

declare(strict_types=1);

namespace App\Support\Pos;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Which day a sale belongs to, on the shopkeeper's clock.
//
// Timestamps are stored in the app timezone (UTC) but a trading day starts and
// ends on the wall clock above the counter. Without this, a sale rung at 00:30
// in Lagos lands under the previous UTC day and the first hour of every day's
// takings is reconciled against the wrong drawer.
//
// Deliberately built on config('reporting.timezone'), which ReportPeriod already
// uses for exactly this reason, rather than a new per-store timezone column: a
// second source of truth for "when does the day end" is how two screens start
// disagreeing about the same money. If stores in different timezones ever need
// separate days, this is the one place that has to learn about it.
final class BusinessDate
{
    /** The trading day now in progress, as Y-m-d. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /**
     * The trading day a stored timestamp falls on.
     *
     * Timestamps are kept in the app timezone, so this is a conversion and not a
     * formatting choice: 23:30 UTC is already half past midnight in Lagos, and
     * reading the date straight off the column would file that sale under
     * yesterday.
     */
    public static function of(string|CarbonInterface|null $timestamp): ?string
    {
        if (blank($timestamp)) {
            return null;
        }

        $at = $timestamp instanceof CarbonInterface
            ? CarbonImmutable::parse($timestamp)
            : CarbonImmutable::parse($timestamp, config('app.timezone', 'UTC'));

        return $at->setTimezone(self::timezone())->toDateString();
    }

    /**
     * The half-open-feeling inclusive window a business date covers, converted to
     * the app timezone so it can be compared against stored timestamps directly.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function boundsFor(string|CarbonInterface $date): array
    {
        $tz = self::timezone();

        $local = $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString(), $tz)
            : CarbonImmutable::parse($date, $tz);

        return [
            self::toAppTimezone($local->startOfDay()),
            self::toAppTimezone($local->endOfDay()),
        ];
    }

    /** Whether a business date is still the day in progress. */
    public static function isToday(string|CarbonInterface $date): bool
    {
        $value = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $value === self::today();
    }

    private static function timezone(): string
    {
        return (string) config('reporting.timezone', 'Africa/Lagos');
    }

    private static function toAppTimezone(CarbonInterface $date): CarbonInterface
    {
        return $date->setTimezone(config('app.timezone', 'UTC'));
    }
}
