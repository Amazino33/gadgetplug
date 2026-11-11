<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Models\PosSale;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonInterface;

/**
 * A cashier's Nth cash sale of the business day.
 *
 * Sales are rung up in the POS app, where tips never show, so the tip appears
 * the next time this person opens a panel page. That can be hours later, which
 * is why this moment stays pending until the end of the Lagos day rather than
 * the usual fifteen minutes. Sales synced from an earlier day never fire it.
 */
class CashSalesTodayReached extends Moment
{
    public const KEY = 'cash_sales_today_reached';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'After N cash sales by this person today';
    }

    public function hasThreshold(): bool
    {
        return true;
    }

    public function expiresAt(): CarbonInterface
    {
        return BusinessDate::boundsFor(BusinessDate::today())[1];
    }

    public function handleSale(PosSale $sale, PendingMoments $pending): void
    {
        if ($sale->payment_method !== 'cash' || $sale->status !== 'completed' || ! $sale->cashier_id) {
            return;
        }

        $today = BusinessDate::today();

        if (BusinessDate::of($sale->completed_at ?? $sale->created_at) !== $today) {
            return;
        }

        $count = PosSale::query()
            ->where('vendor_id', $sale->vendor_id)
            ->where('cashier_id', $sale->cashier_id)
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->whereBetween('completed_at', BusinessDate::boundsFor($today))
            ->count();

        $pending->record((int) $sale->cashier_id, (int) $sale->vendor_id, self::KEY, $count, $this->expiresAt());
    }
}
