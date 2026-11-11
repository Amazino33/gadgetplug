<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Support\Pos\BusinessDate;
use Carbon\CarbonInterface;

/**
 * A cashier's Nth cash sale of the business day. Fired by MomentRecorder.
 *
 * Sales are rung up in the POS app, where tips never show, so the tip appears
 * the next time this person opens a panel page. That can be hours later, which
 * is why this moment stays pending until the end of the Lagos day rather than
 * the usual fifteen minutes. Sales are counted by their own sale date, so an
 * offline sale from an earlier day that syncs today never fires it.
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
}
