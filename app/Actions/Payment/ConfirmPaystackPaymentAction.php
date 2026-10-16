<?php

namespace App\Actions\Payment;

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Order;
use App\Services\Payment\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// The single path by which a Paystack payment turns a pending order into a paid
// one. Used by the browser callback AND the webhook, which race each other on
// every successful payment — so it is idempotent: whichever arrives first does
// the work, the other finds the order already past 'pending' and changes nothing.
//
// Verification happens against Paystack's API, never from the request alone:
// the amount (kobo) and currency must match the order before it is marked paid.
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
        $order = Order::where('reference', $reference)->first();

        if (! $order) {
            return self::NOT_FOUND;
        }

        // Cheap early exit before spending an API call. Re-checked under lock below.
        if ($order->status !== 'pending') {
            return self::ALREADY;
        }

        // Network call kept outside the transaction so no row lock is held across it.
        $transaction = $this->paystack->verify($reference);

        if ($transaction === null || ($transaction['status'] ?? null) !== 'success') {
            return self::UNVERIFIED;
        }

        $expectedKobo = (int) round((float) $order->total_amount * 100);

        if ((int) ($transaction['amount'] ?? -1) !== $expectedKobo
            || strtoupper((string) ($transaction['currency'] ?? '')) !== 'NGN') {
            Log::error('Paystack payment does not match the order — not marking paid.', [
                'order_id'      => $order->id,
                'reference'     => $reference,
                'expected_kobo' => $expectedKobo,
                'paid_kobo'     => $transaction['amount'] ?? null,
                'currency'      => $transaction['currency'] ?? null,
            ]);

            return self::MISMATCH;
        }

        return DB::transaction(function () use ($order): string {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                return self::ALREADY;
            }

            $locked->load('items.product');

            try {
                // Savepoint: if a later line fails, earlier reservations roll
                // back with it rather than staying held against an order that
                // is about to be flagged for a human.
                DB::transaction(function () use ($locked): void {
                    foreach ($locked->items as $item) {
                        if (! $item->tracksStock()) {
                            continue;
                        }

                        $this->reserveStock->execute(
                            productId:   $item->product_id,
                            quantity:    $item->quantity,
                            reference:   $locked->reference,
                            description: 'Reserved on Paystack payment confirmation.',
                            orderItemId: $item->id,
                        );
                    }
                });
            } catch (Throwable $e) {
                Log::error("Stock reservation failed after payment for order {$locked->id}: ".$e->getMessage());

                $locked->update(['status' => 'paid_but_failed_stock']);

                return self::FAILED_STOCK;
            }

            $locked->update(['status' => 'paid']);

            return self::PAID;
        });
    }
}
