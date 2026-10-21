<?php

namespace App\Actions\Payment;

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Checkout;
use App\Models\Order;
use App\Services\Payment\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// The single path by which a Paystack payment turns a pending checkout's child
// orders into paid ones. Used by the browser callback AND the webhook, which
// race each other on every successful payment — so it is idempotent: whichever
// arrives first does the work, the other finds no child order still 'pending'
// and changes nothing.
//
// Verification happens against Paystack's API, never from the request alone:
// the amount (kobo) must match the checkout's grand total and the currency
// must be NGN before anything is marked paid.
class ConfirmPaystackPaymentAction
{
    public const PAID         = 'paid';
    public const ALREADY      = 'already';      // not pending any more — nothing done
    public const FAILED_STOCK = 'failed_stock'; // paid, but a unit could not be reserved
    public const NOT_FOUND    = 'not_found';    // no order with this reference
    public const UNVERIFIED   = 'unverified';   // Paystack could not confirm (may be transient)
    public const MISMATCH     = 'mismatch';     // Paystack says paid, but not for this order

    public function __construct(
        private PaystackClient $paystack,
        private ReserveStockAction $reserveStock,
    ) {}

    public function execute(string $reference): string
    {
        // Paystack is paid against the checkout. A reference with no checkout
        // (an order created outside the checkout flow) is treated as a
        // checkout of one, so it confirms exactly as it always did.
        $checkout = Checkout::where('reference', $reference)->first();

        $orders = $checkout
            ? $checkout->orders()->get()
            : Order::where('reference', $reference)->get();

        if ($orders->isEmpty()) {
            return self::NOT_FOUND;
        }

        // Cheap early exit before spending an API call. Re-checked under lock below.
        if ($orders->where('status', 'pending')->isEmpty()) {
            return self::ALREADY;
        }

        // Network call kept outside the transaction so no row lock is held across it.
        $transaction = $this->paystack->verify($reference);

        if ($transaction === null || ($transaction['status'] ?? null) !== 'success') {
            return self::UNVERIFIED;
        }

        $expectedKobo = (int) round((float) ($checkout ? $checkout->grand_total : $orders->first()->total_amount) * 100);

        if ((int) ($transaction['amount'] ?? -1) !== $expectedKobo
            || strtoupper((string) ($transaction['currency'] ?? '')) !== 'NGN') {
            Log::error('Paystack payment does not match the order — not marking paid.', [
                'checkout_id'   => $checkout?->id,
                'order_ids'     => $orders->pluck('id')->all(),
                'reference'     => $reference,
                'expected_kobo' => $expectedKobo,
                'paid_kobo'     => $transaction['amount'] ?? null,
                'currency'      => $transaction['currency'] ?? null,
            ]);

            return self::MISMATCH;
        }

        return DB::transaction(function () use ($orders): string {
            // Every child locked, in id order so two racing confirmations
            // queue behind each other rather than deadlock. Whichever gets the
            // locks first does the work; the other finds nothing pending.
            $locked = Order::whereKey($orders->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->where('status', 'pending');

            if ($locked->isEmpty()) {
                return self::ALREADY;
            }

            $anyFailed = false;

            // Each child order on its own: one vendor's stock problem flags
            // that vendor's order for a human and does not hold up the others,
            // which are paid for and ready to fulfil.
            foreach ($locked as $order) {
                $order->load('items.product');

                try {
                    // Savepoint: if a later line fails, earlier reservations roll
                    // back with it rather than staying held against an order that
                    // is about to be flagged for a human.
                    DB::transaction(function () use ($order): void {
                        foreach ($order->items as $item) {
                            if (! $item->tracksStock()) {
                                continue;
                            }

                            $this->reserveStock->execute(
                                productId:   $item->product_id,
                                quantity:    $item->quantity,
                                reference:   $order->reference,
                                description: 'Reserved on Paystack payment confirmation.',
                                orderItemId: $item->id,
                            );
                        }
                    });
                } catch (Throwable $e) {
                    Log::error("Stock reservation failed after payment for order {$order->id}: ".$e->getMessage());

                    $order->update(['status' => 'paid_but_failed_stock']);
                    $anyFailed = true;

                    continue;
                }

                $order->update(['status' => 'paid']);
            }

            return $anyFailed ? self::FAILED_STOCK : self::PAID;
        });
    }
}
