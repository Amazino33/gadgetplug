<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\PosMissingDayReason;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Trading days that were opened or sold on but never counted.
 *
 * The till used to remember only days opened on that same device. A day opened
 * on another till, or one with sales and no shift at all — Zeelink Phones'
 * Saturday 26/09/2026, whose sales arrived on the Monday — was never asked for
 * again, so its drawer was simply never counted.
 *
 * A day is missing when, for one cashier at one branch, it has an End of Day
 * record still open, or sales but no record. Today is never missing: it is
 * still being traded.
 */
class MissingEndOfDays
{
    /** How far back a cashier is asked to account for. */
    public const LOOKBACK_DAYS = 14;

    /**
     * One cashier's missing days at one branch, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forCashier(int $vendorId, int $storeId, int $cashierId): Collection
    {
        return $this->find($vendorId, $storeId, $cashierId);
    }

    /**
     * Every cashier's missing days at a branch — or across the business when no
     * branch is given — oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forBranch(int $vendorId, ?int $storeId): Collection
    {
        return $this->find($vendorId, $storeId, null);
    }

    private function find(int $vendorId, ?int $storeId, ?int $cashierId): Collection
    {
        $today = BusinessDate::today();
        $since = CarbonImmutable::parse($today)->subDays(self::LOOKBACK_DAYS)->toDateString();
        [$from] = BusinessDate::boundsFor($since);
        [$todayStarts] = BusinessDate::boundsFor($today);

        $key = fn ($store, $cashier, $date) => "{$store}|{$cashier}|{$date}";

        $sessions = PosSession::query()
            ->where('vendor_id', $vendorId)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->whereNotNull('store_id')
            ->whereNotNull('business_date')
            ->whereDate('business_date', '>=', $since)
            ->whereDate('business_date', '<', $today)
            ->get()
            ->keyBy(fn (PosSession $s) => $key($s->store_id, $s->cashier_id, $s->business_date->toDateString()));

        $sales = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->whereNotNull('store_id')
            ->where('status', '!=', 'voided')
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $todayStarts)
            ->get(['store_id', 'cashier_id', 'total', 'completed_at'])
            ->groupBy(fn (PosSale $s) => $key($s->store_id, $s->cashier_id, BusinessDate::of($s->completed_at)));

        $days = collect();

        // Opened and never counted.
        foreach ($sessions as $k => $session) {
            if ($session->isOpen()) {
                $days[$k] = ['session' => $session];
            }
        }

        // Sold on, with no record at all.
        foreach ($sales->keys() as $k) {
            if (! $sessions->has($k)) {
                $days[$k] = ['session' => null];
            }
        }

        $reasons = PosMissingDayReason::query()
            ->with('cashier:id,name')
            ->where('vendor_id', $vendorId)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->whereDate('business_date', '>=', $since)
            ->orderByDesc('given_on')
            ->get()
            ->groupBy(fn (PosMissingDayReason $r) => $key($r->store_id, $r->cashier_id, $r->business_date->toDateString()));

        return $days
            ->map(function (array $day, string $k) use ($sales, $reasons) {
                [$store, $cashier, $date] = explode('|', $k);
                $daySales = $sales->get($k, collect());
                $session = $day['session'];

                return [
                    'business_date' => $date,
                    'store_id'      => (int) $store,
                    'cashier_id'    => (int) $cashier,
                    // The record to close, if one was opened. Null means the day
                    // has to be opened before it can be counted, float and all.
                    'session'       => $session ? [
                        'id'            => $session->id,
                        'opening_float' => (float) $session->opening_float,
                    ] : null,
                    'sales_count'   => $daySales->count(),
                    'sales_total'   => round((float) $daySales->sum('total'), 2),
                    // Every time it has been put off, newest first.
                    'reasons'       => $reasons->get($k, collect())
                        ->map(fn (PosMissingDayReason $r) => [
                            'given_on' => $r->given_on->toDateString(),
                            'reason'   => $r->reason,
                        ])->values()->all(),
                ];
            })
            ->sortBy(fn (array $d) => $d['business_date'].'|'.$d['cashier_id'])
            ->values();
    }
}
