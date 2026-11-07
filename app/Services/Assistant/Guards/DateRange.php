<?php

namespace App\Services\Assistant\Guards;

use App\Services\Reporting\ReportPeriod;
use Carbon\CarbonInterface;

/**
 * A reporting window worked out in the vendor's timezone.
 *
 * A thin wrapper over ReportPeriod — the class every dashboard widget already
 * uses — so "today" in the chat starts at the same Lagos midnight as "today"
 * on the dashboard. Only the human-readable bounds are added here, because an
 * answer has to state the range it covers.
 */
final class DateRange
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_7', 'this_month', 'last_month', 'this_year', 'custom'];

    private function __construct(public readonly ReportPeriod $period) {}

    /**
     * @param  array{preset?: string, from?: string, to?: string}  $input
     */
    public static function resolve(array $input): self
    {
        $preset = in_array($input['preset'] ?? null, self::PRESETS, true) ? $input['preset'] : 'today';

        return new self(ReportPeriod::fromFilters([
            'preset' => $preset,
            'from' => $input['from'] ?? null,
            'to' => $input['to'] ?? null,
        ]));
    }

    public static function today(): self
    {
        return self::resolve(['preset' => 'today']);
    }

    public function from(): CarbonInterface
    {
        return $this->period->from;
    }

    public function to(): CarbonInterface
    {
        return $this->period->to;
    }

    /** @return array{label: string, from: string, to: string, timezone: string} */
    public function describe(): array
    {
        $tz = (string) config('reporting.timezone', 'Africa/Lagos');

        return [
            'label' => $this->period->label,
            'from' => $this->period->from->copy()->setTimezone($tz)->format('D j M Y, H:i'),
            'to' => $this->period->to->copy()->setTimezone($tz)->format('D j M Y, H:i'),
            'timezone' => $tz,
        ];
    }
}
