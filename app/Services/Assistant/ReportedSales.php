<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\Store;
use App\Services\Reporting\SalesReportService;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The assistant's sales figures, from the dashboard's own definition.
 *
 * Every number comes from SalesReportService::summary() — the call the home
 * dashboard, the dashboard widgets and the Sales Report make — so "how much
 * did we sell" in the chat cannot disagree with the screen: revenue net of
 * discounts and refunds, excluding VAT, POS and online.
 *
 * Several stores of one vendor are added up store by store, except when
 * they are ALL of that vendor's stores (more than one): then it is the
 * vendor-wide call, exactly the dashboard's "All Stores", which also counts
 * online revenue no branch was allocated. Stores of different vendors (only
 * possible without a business scope, for an owner of several) are summed
 * per vendor.
 */
final class ReportedSales
{
    public function __construct(private SalesReportService $reports) {}

    /**
     * @param  array<int, int>  $storeIds
     * @return array{revenue: float, cost: float, profit: float, margin: float, orders: int, units: int|float, online_revenue: float, pos_revenue: float, cost_is_estimated: bool}
     */
    public function summary(array $storeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $total = self::zero();

        foreach ($this->byVendor($storeIds) as $vendorId => $stores) {
            $parts = $this->coversWholeVendor((int) $vendorId, $stores)
                ? [$this->reports->summary((int) $vendorId, $from, $to, null)]
                : $stores->map(fn (Store $s) => $this->reports->summary((int) $vendorId, $from, $to, (int) $s->id))->all();

            foreach ($parts as $part) {
                $total = self::add($total, $part);
            }
        }

        $total['profit'] = $total['revenue'] - $total['cost'];
        $total['margin'] = $total['revenue'] > 0 ? ($total['profit'] / $total['revenue']) * 100 : 0.0;

        return $total;
    }

    /**
     * One summary() per business day in the range — the same days, the same
     * definition as the dashboard's revenue trend. The first and last days
     * are clipped to the range itself.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array{date: string, revenue: float, orders: int}>
     */
    public function byDay(array $storeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        $day = CarbonImmutable::parse(BusinessDate::of($from));
        $last = CarbonImmutable::parse(BusinessDate::of($to));

        while ($day->lte($last)) {
            [$start, $end] = BusinessDate::boundsFor($day->toDateString());
            $start = $start->greaterThan($from) ? $start : $from;
            $end = $end->lessThan($to) ? $end : $to;

            $s = $this->summary($storeIds, $start, $end);
            $days[] = ['date' => $day->toDateString(), 'revenue' => round($s['revenue'], 2), 'orders' => $s['orders']];

            $day = $day->addDay();
        }

        return $days;
    }

    /** @return Collection<int|string, Collection<int, Store>> */
    private function byVendor(array $storeIds): Collection
    {
        return Store::query()->whereIn('id', $storeIds)->orderBy('id')->get(['id', 'vendor_id'])->groupBy('vendor_id');
    }

    /** @param  Collection<int, Store>  $stores */
    private function coversWholeVendor(int $vendorId, Collection $stores): bool
    {
        $all = Store::query()->where('vendor_id', $vendorId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return count($all) > 1 && $stores->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $all;
    }

    /** @return array<string, mixed> */
    private static function zero(): array
    {
        return ['revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'margin' => 0.0, 'orders' => 0, 'units' => 0,
            'online_revenue' => 0.0, 'pos_revenue' => 0.0, 'cost_is_estimated' => false];
    }

    /** @return array<string, mixed> */
    private static function add(array $total, array $part): array
    {
        foreach (['revenue', 'cost', 'online_revenue', 'pos_revenue'] as $k) {
            $total[$k] += (float) $part[$k];
        }

        $total['orders'] += (int) $part['orders'];
        $total['units'] += $part['units'];
        $total['cost_is_estimated'] = $total['cost_is_estimated'] || (bool) $part['cost_is_estimated'];

        return $total;
    }
}
