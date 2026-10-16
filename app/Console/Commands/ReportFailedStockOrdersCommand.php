<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

// Read-only. Lists orders sitting in paid_but_failed_stock and flags the ones
// that contain a resold (VendorLink) line — the likely victims of the old
// Paystack callback, which tried to reserve stock a listing never holds.
// Changes nothing; repairing these is a separate, deliberate step.
class ReportFailedStockOrdersCommand extends Command
{
    protected $signature = 'orders:report-failed-stock';

    protected $description = 'List paid_but_failed_stock orders and flag those containing linked (resold) lines. Read-only.';

    public function handle(): int
    {
        $orders = Order::where('status', 'paid_but_failed_stock')
            ->with('items.product')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders are in paid_but_failed_stock.');

            return self::SUCCESS;
        }

        $rows = $orders->map(function (Order $order): array {
            $linked  = $order->items->filter(fn ($item) => ! $item->tracksStock())->count();
            $tracked = $order->items->count() - $linked;

            return [
                $order->id,
                $order->reference,
                $order->created_at?->format('Y-m-d H:i'),
                number_format((float) $order->total_amount, 2),
                $order->items->count(),
                $linked,
                $linked > 0 && $tracked === 0 ? 'YES (only linked lines)' : ($linked > 0 ? 'YES (mixed)' : 'no'),
            ];
        });

        $this->table(['ID', 'Reference', 'Placed', 'Total (₦)', 'Lines', 'Linked lines', 'Likely linked-line bug'], $rows->all());

        $suspect = $rows->filter(fn (array $r) => $r[5] > 0)->count();
        $this->line("{$orders->count()} order(s) in paid_but_failed_stock; {$suspect} contain linked lines.");
        $this->comment('Read-only report. Nothing was changed.');

        return self::SUCCESS;
    }
}
