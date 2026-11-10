<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Exceptions\Assistant\InvalidPeriod;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The dates a tool answers for, worked out in code on the Lagos trading day.
 *
 * The model names a period ("this_week") or gives plain dates (YYYY-MM-DD);
 * it never does calendar arithmetic, and it is never trusted to remember to
 * send dates at all. With neither, the tool's own default applies (today for
 * sales, for instance), never a silent "last 7 days". Every result carries
 * the label of the period it covered, so the answer can say it.
 *
 * The windows match the dashboard's (ReportPeriod) where the names match:
 * the week starts on the app's first day of the week, and today ends now.
 *
 * Why: on 2026-11-10 the live assistant was asked "how much is today
 * revenue?" at 08:20, the model sent no dates, the old code fell back to the
 * last 7 days, and the answer gave the week's ₦8,358,295 (161 orders) as
 * today's, when today was ₦0.
 */
final class AssistantPeriod
{
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'this_year' => 'This year',
    ];

    public function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $fromDate,
        public readonly CarbonImmutable $toDate,
    ) {}

    /**
     * @param  array<string, mixed>  $args  the tool call's arguments: period, date_from, date_to
     *
     * @throws InvalidPeriod
     */
    public static function resolve(array $args, string $default = 'today'): self
    {
        $period = strtolower(trim((string) ($args['period'] ?? '')));

        if ($period !== '') {
            if (! array_key_exists($period, self::PERIODS)) {
                throw new InvalidPeriod("Unknown period '{$period}'. Use one of: ".implode(', ', array_keys(self::PERIODS)).', or date_from/date_to as YYYY-MM-DD.');
            }

            return self::named($period);
        }

        $from = self::parseDate($args['date_from'] ?? null, 'date_from');
        $to = self::parseDate($args['date_to'] ?? null, 'date_to');

        if ($from === null && $to === null) {
            return self::named($default);
        }

        $today = BusinessDate::now()->startOfDay();
        $from ??= $to;
        $to ??= $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Nothing has happened after today yet.
        $to = $to->greaterThan($today) ? $today : $to;
        $from = $from->greaterThan($today) ? $today : $from;

        return new self('custom', $from, $to);
    }

    /** A named period, on the Lagos calendar. */
    public static function named(string $key): self
    {
        $now = BusinessDate::now()->startOfDay();
        $startOfThisWeek = $now->startOfWeek();
        $startOfThisMonth = $now->startOfMonth();

        // No subMonth()/subYear() anywhere: on the 31st they overflow into the
        // wrong month. Last month is "the day before this month began".
        [$from, $to] = match ($key) {
            'today' => [$now, $now],
            'yesterday' => [$now->subDay(), $now->subDay()],
            'this_week' => [$startOfThisWeek, $now],
            'last_week' => [$startOfThisWeek->subWeek(), $startOfThisWeek->subDay()],
            'last_7_days' => [$now->subDays(6), $now],
            'this_month' => [$startOfThisMonth, $now],
            'last_month' => [$startOfThisMonth->subDay()->startOfMonth(), $startOfThisMonth->subDay()],
            'last_30_days' => [$now->subDays(29), $now],
            'this_year' => [$now->startOfYear(), $now],
            default => throw new InvalidPeriod("Unknown period '{$key}'."),
        };

        return new self($key, $from, $to);
    }

    /** First and last moment covered, as stored timestamps (UTC); the end is never later than now. */
    public function from(): CarbonInterface
    {
        return BusinessDate::boundsFor($this->fromDate)[0];
    }

    public function to(): CarbonInterface
    {
        $end = BusinessDate::boundsFor($this->toDate)[1];

        return $end->greaterThan(now()) ? now() : $end;
    }

    /** What the answer should call it: "Today (Tue 10 Nov 2026)", "Last month (1 to 31 Oct 2026)". */
    public function label(): string
    {
        $name = self::PERIODS[$this->key] ?? null;
        $span = $this->fromDate->equalTo($this->toDate)
            ? $this->fromDate->format('D j M Y')
            : $this->fromDate->format('j M').($this->fromDate->year !== $this->toDate->year ? $this->fromDate->format(' Y') : '').' to '.$this->toDate->format('j M Y');

        return $name ? "{$name} ({$span})" : $span;
    }

    /** @return array{from: string, to: string, label: string} for the tool result */
    public function toArray(): array
    {
        return [
            'from' => $this->fromDate->toDateString(),
            'to' => $this->toDate->toDateString(),
            'label' => $this->label(),
        ];
    }

    /** @throws InvalidPeriod */
    private static function parseDate(mixed $value, string $field): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // A period name where a date belongs ("date_from": "today") is read as that period's start or end.
        if (array_key_exists(strtolower($value), self::PERIODS)) {
            $named = self::named(strtolower($value));

            return $field === 'date_to' ? $named->toDate : $named->fromDate;
        }

        if (! preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            throw new InvalidPeriod("{$field} must be YYYY-MM-DD, got '{$value}'.");
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $m[1], BusinessDate::timezone());

        if ($date === false || $date->toDateString() !== $m[1]) {
            throw new InvalidPeriod("{$field} is not a real date: '{$m[1]}'.");
        }

        return $date;
    }
}
