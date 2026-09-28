<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosZReport;
use App\Support\Pos\BusinessDate;

/**
 * The signed slip.
 *
 * Written from the session's frozen figures rather than recomputed, so a
 * reprint is the same piece of paper it was the first time.
 *
 * Its sales are gathered the way the reconciliation gathers them — by
 * cashier, branch and business date — rather than by pos_session_id. Sales
 * replayed through the offline sync endpoint carry no session id at all, so
 * the old slip silently omitted every sale rung while the till was offline.
 *
 * Out of the controller so the one repair that may move a cash-up to another
 * day (pos:redate-cash-up) reprints the slip the same way the close did.
 */
class ZReportWriter
{
    /** Build the slip without saving it — for a day still in progress. */
    public function build(PosSession $session): PosZReport
    {
        [$from, $to] = BusinessDate::boundsFor($session->business_date->toDateString());

        $sales = PosSale::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('store_id', $session->store_id)
            ->where('cashier_id', $session->cashier_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to])
            ->get();

        $returns = PosReturn::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('cashier_id', $session->cashier_id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        return new PosZReport([
            'pos_session_id'      => $session->id,
            'vendor_id'           => $session->vendor_id,
            'cashier_id'          => $session->cashier_id,
            'report_date'         => $session->business_date->toDateString(),
            'cash_sales'          => $sales->where('payment_method', 'cash')->sum('total'),
            'card_sales'          => $sales->where('payment_method', 'card')->sum('total'),
            'bank_transfer_sales' => $sales->where('payment_method', 'bank_transfer')->sum('total'),
            'total_sales'         => $sales->sum('total'),
            'total_vat'           => $sales->sum('vat_amount'),
            'total_discounts'     => $sales->sum('discount_amount'),
            'total_returns'       => $returns->sum('refund_amount'),
            'transaction_count'   => $sales->count(),
            'return_count'        => $returns->count(),
            'opening_float'       => $session->opening_float,
            'cash_expected'       => $session->expected_cash,
            'cash_counted'        => $session->counted_cash,
            'cash_variance'       => $session->cash_variance,
            'terminal_expected'   => $session->expected_terminal,
            'terminal_counted'    => $session->counted_terminal,
            'terminal_variance'   => $session->terminal_variance,
            'notes'               => $session->notes,
            'generated_at'        => now(),
        ]);
    }

    /** Build the slip and save it as this session's one Z-report. */
    public function write(PosSession $session): PosZReport
    {
        $report = $this->build($session);

        return PosZReport::updateOrCreate(
            ['pos_session_id' => $report->pos_session_id],
            $report->toArray()
        );
    }
}
