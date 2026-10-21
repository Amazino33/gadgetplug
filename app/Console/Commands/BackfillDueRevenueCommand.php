<?php

namespace App\Console\Commands;

use App\Actions\Finance\RecognizeOrderRevenueAction;
use App\Actions\VendorLink\BookSupplierPayableAction;
use App\Models\Order;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * One-off, for the switch to Due from GadgetPlug: books revenue for Paystack
 * and wallet orders that were paid but never had it recorded, into each
 * vendor's Due from GadgetPlug account, dated the day they were paid.
 *
 * GadgetPlug holds that money (Paystack settles to it; a wallet is its
 * balance), so Due is where it belongs. Pay-on-delivery orders are not touched:
 * their money is recorded by an admin when it is collected.
 *
 * Read-only unless --force. Idempotent: an order that already has revenue is
 * never selected, and each booking re-checks under a row lock, so a second run
 * books nothing.
 *
 * The paid date is the earliest of these that exists, and the table says which:
 *   Paystack — the activity-log entry where the status became 'paid', else
 *              reserved_at (stamped when payment confirmation reserved the
 *              stock), else the order's created_at;
 *   wallet   — the wallet debit, else created_at.
 *
 * Any supplier payable for resold lines books at the same time, at today's
 * date and the supplier's price today (the payable ledger has no back-dating).
 */
class BackfillDueRevenueCommand extends Command
{
    protected $signature = 'orders:backfill-due-revenue
                            {--force : Write the bookings (without it, only the report is printed)}';

    protected $description = 'Book unrecorded Paystack/wallet revenue into Due from GadgetPlug, dated at the original paid date (dry run unless --force)';

    /** Statuses at which the money has been received, per payment method. */
    private const PAID_STATUSES = [
        'paystack' => ['paid', 'packed', 'shipped', 'delivered'],
        'wallet'   => ['confirmed', 'packed', 'shipped', 'delivered'],
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->line($force ? '<comment>Writing bookings (--force).</comment>' : '<info>Dry run — nothing is written. Add --force to book.</info>');
        $this->newLine();

        $orders = $this->candidates();

        if ($orders->isEmpty()) {
            $this->info('Nothing to book: every paid Paystack and wallet order already has its revenue recorded.');
            $this->reportUntouched();

            return self::SUCCESS;
        }

        $rows = $orders->map(fn (Order $order) => [
            'order'  => $order,
            'vendor' => $order->items->first()?->vendor?->name ?? '(no vendor)',
            'group'  => ucfirst($order->payment_method).' — '.$order->status,
        ] + $this->paidDate($order));

        foreach ($rows->groupBy('group') as $group => $groupRows) {
            $this->line("<options=bold>{$group}</>");
            $this->table(
                ['Vendor', 'Orders', 'Amount (NGN)'],
                $groupRows->groupBy('vendor')->map(fn (Collection $r, string $vendor) => [
                    $vendor,
                    $r->count(),
                    number_format($r->sum(fn ($row) => (float) $row['order']->total_amount), 2),
                ])->values()->push(['<options=bold>Total</>', $groupRows->count(), number_format($groupRows->sum(fn ($row) => (float) $row['order']->total_amount), 2)])->all(),
            );
        }

        $this->line('<options=bold>Orders and the date each would be booked at</>');
        $this->table(
            ['Order', 'Vendor', 'Amount (NGN)', 'Paid date', 'Date from'],
            $rows->map(fn (array $row) => [
                $row['order']->reference,
                $row['vendor'],
                number_format((float) $row['order']->total_amount, 2),
                $row['at']->toDateTimeString(),
                $row['source'],
            ])->all(),
        );

        $this->reportUntouched();

        if (! $force) {
            return self::SUCCESS;
        }

        $booked = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $result = $this->book($row['order'], $row['at']);

            $result === null ? $booked++ : $skipped[$row['order']->reference] = $result;
        }

        $this->newLine();
        $this->info("Booked {$booked} order(s) into Due from GadgetPlug.");

        foreach ($skipped as $reference => $reason) {
            $this->warn("Not booked: {$reference} ({$reason})");
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Order> */
    private function candidates(): Collection
    {
        return Order::query()
            ->whereNull('revenue_recognized_at')
            ->where(function ($query) {
                foreach (self::PAID_STATUSES as $method => $statuses) {
                    $query->orWhere(fn ($q) => $q->where('payment_method', $method)->whereIn('status', $statuses));
                }
            })
            ->with('items.vendor')
            ->orderBy('id')
            ->get();
    }

    /** Paid orders deliberately left alone, so nothing is silently skipped. */
    private function reportUntouched(): void
    {
        $stuck = Order::where('status', 'paid_but_failed_stock')->whereNull('revenue_recognized_at')->count();

        if ($stuck > 0) {
            $this->newLine();
            $this->warn("{$stuck} paid order(s) with a stock problem (paid_but_failed_stock) are not booked here — they need a human decision first (orders:report-failed-stock).");
        }
    }

    /** @return array{at: CarbonImmutable, source: string} */
    private function paidDate(Order $order): array
    {
        if ($order->payment_method === 'wallet') {
            $debit = WalletTransaction::where('order_id', $order->id)->where('type', 'debit')->orderBy('id')->value('created_at');

            return $debit
                ? ['at' => CarbonImmutable::parse($debit), 'source' => 'wallet debit']
                : ['at' => CarbonImmutable::parse($order->created_at), 'source' => 'order created (no debit found)'];
        }

        $logged = Activity::query()
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->orderBy('id')
            ->get()
            ->first(fn (Activity $activity) => data_get($activity->properties, 'attributes.status') === 'paid');

        if ($logged) {
            return ['at' => CarbonImmutable::parse($logged->created_at), 'source' => 'activity log (status → paid)'];
        }

        if ($order->reserved_at) {
            return ['at' => CarbonImmutable::parse($order->reserved_at), 'source' => 'stock reserved on payment'];
        }

        return ['at' => CarbonImmutable::parse($order->created_at), 'source' => 'order created (fallback)'];
    }

    private function book(Order $order, CarbonImmutable $paidAt): ?string
    {
        try {
            return DB::transaction(function () use ($order, $paidAt) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->isRevenueRecognized()) {
                    return 'already_recognized';
                }

                $reason = app(RecognizeOrderRevenueAction::class)->execute($locked, occurredAt: $paidAt);

                if ($reason === null) {
                    app(BookSupplierPayableAction::class)->execute($locked);

                    activity()->performedOn($locked)->event('revenue_backfilled')
                        ->withProperties(['paid_at' => $paidAt->toDateTimeString(), 'amount' => (float) $locked->total_amount])
                        ->log('Revenue backfilled into Due from GadgetPlug (one-off, dated at payment)');
                }

                return $reason;
            });
        } catch (Throwable $e) {
            return 'error: '.$e->getMessage();
        }
    }
}
