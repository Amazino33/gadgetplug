<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateSetting;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function __construct(private RateResolver $rateResolver) {}

    /**
     * Creates exactly one frozen commission for this order, with one frozen
     * line per order item (rate resolved per-product, since a cart can span
     * products/categories at different rates). Idempotent: a second call for
     * the same order returns the existing row rather than creating another —
     * the unique constraint on affiliate_commissions.order_id backs this at
     * the DB level too, not just here.
     */
    public function createForOrder(Order $order, Affiliate $affiliate): ?AffiliateCommission
    {
        $existing = AffiliateCommission::where('order_id', $order->id)->first();

        if ($existing) {
            return $existing;
        }

        $order->loadMissing('items.product.category');

        if ($order->items->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($order, $affiliate) {
            $commission = AffiliateCommission::create([
                'affiliate_id' => $affiliate->id,
                'order_id'     => $order->id,
                'amount'       => 0,
                'status'       => 'pending',
            ]);

            $total = 0.0;
            $marginCapFraction = (float) AffiliateSetting::current()->margin_cap_fraction;

            foreach ($order->items as $item) {
                $rate = $this->rateResolver->resolveForProduct($item->product, $affiliate);
                $base = (float) ($item->quantity * $item->unit_price);
                $amount = round($base * $rate / 100, 2);

                if ($affiliate->level !== null) {
                    $unitCost = $item->unit_cost ?? $item->product->cost_price;

                    if ($unitCost === null) {
                        // No margin data for this line — no level boost applies,
                        // fall back to the plain (unboosted) base rate rather
                        // than guessing or blocking the commission outright.
                        $rate = $this->rateResolver->resolveForProduct($item->product);
                        $amount = round($base * $rate / 100, 2);
                    } else {
                        $margin = max(((float) $item->unit_price - (float) $unitCost) * $item->quantity, 0);
                        $cap = round($margin * $marginCapFraction, 2);
                        $amount = min($amount, $cap);
                    }
                }

                $commission->items()->create([
                    'order_item_id' => $item->id,
                    'rate'          => $rate,
                    'base_amount'   => round($base, 2),
                    'amount'        => $amount,
                ]);

                $total += $amount;
            }

            $commission->update(['amount' => round($total, 2)]);

            return $commission->fresh('items');
        });
    }

    /**
     * Order delivered — starts the hold. Idempotent: only acts on a
     * commission still 'pending'.
     */
    public function startReturnWindow(Order $order): void
    {
        $commission = AffiliateCommission::where('order_id', $order->id)
            ->where('status', 'pending')
            ->first();

        if (! $commission) {
            return;
        }

        $commission->update([
            'status'                    => 'return_window',
            'return_window_started_at' => now(),
        ]);
    }

    /**
     * Order cancelled/refunded/returned — rejects the commission regardless
     * of its current state. If it had already cleared to 'available' (a
     * ledger credit already exists), writes a compensating reversal rather
     * than mutating the original credit row.
     */
    public function reject(Order $order, string $reason): void
    {
        $commission = AffiliateCommission::where('order_id', $order->id)
            ->where('status', '!=', 'rejected')
            ->first();

        if (! $commission) {
            return;
        }

        DB::transaction(function () use ($commission, $reason) {
            $wasAvailable = $commission->status === 'available';

            $commission->update([
                'status'          => 'rejected',
                'rejected_at'     => now(),
                'rejected_reason' => $reason,
            ]);

            if ($wasAvailable) {
                $creditedAmount = $commission->walletTransactions()
                    ->where('type', 'credit')
                    ->sum('amount');

                if ((float) $creditedAmount > 0) {
                    $commission->walletTransactions()->create([
                        'affiliate_id' => $commission->affiliate_id,
                        'type'         => 'reversal',
                        'amount'       => -$creditedAmount,
                        'description'  => "Reversal — order rejected ({$reason}) after commission #{$commission->id} was already credited.",
                    ]);
                }
            }
        });

        Log::info("Affiliate commission #{$commission->id} rejected ({$reason}).");
    }
}
