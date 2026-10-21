<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Backfill: one checkout per existing order (1:1).
//
// Historical orders are NOT split, including the ones that mix vendors — those
// are counted and reported so the owner can decide what to do with them. Each
// backfilled checkout takes the order's own reference, so every reference a
// customer was ever given still resolves on /track and at Paystack.
//
// Also normalises the legacy payment_method 'pod' to 'pay_on_delivery', which
// is the only value the code checks for.
//
// down() unlinks and removes the backfilled checkouts. The 'pod' normalisation
// is deliberately not reversed: nothing records which rows held it and every
// code path reads 'pay_on_delivery', so restoring it would only re-break them.
return new class extends Migration
{
    public function up(): void
    {
        $normalised = DB::table('orders')->where('payment_method', 'pod')->update(['payment_method' => 'pay_on_delivery']);

        $created = 0;

        DB::table('orders')
            ->whereNull('checkout_id')
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (&$created) {
                foreach ($orders as $order) {
                    $checkoutId = DB::table('checkouts')->insertGetId([
                        'reference'           => $order->reference,
                        'idempotency_key'     => $order->idempotency_key,
                        'user_id'             => $order->user_id,
                        'customer_name'       => $order->customer_name,
                        'customer_email'      => $order->customer_email,
                        'customer_phone'      => $order->customer_phone,
                        'shipping_address'    => $order->shipping_address,
                        'local_government'    => $order->local_government,
                        'payment_method'      => $order->payment_method,
                        'items_total'         => $order->total_amount,
                        'delivery_fee'        => 0,
                        'grand_total'         => $order->total_amount,
                        'fbp'                 => $order->fbp,
                        'fbc'                 => $order->fbc,
                        // Orders already past the "money is settled" point have
                        // had their Purchase sent; mark it so nothing re-sends.
                        'purchase_tracked_at' => in_array($order->status, ['confirmed', 'paid', 'shipped', 'delivered'], true)
                            ? $order->updated_at
                            : null,
                        'created_at'          => $order->created_at,
                        'updated_at'          => $order->updated_at,
                    ]);

                    DB::table('orders')->where('id', $order->id)->update(['checkout_id' => $checkoutId]);
                    $created++;
                }
            });

        $mixed = DB::table('order_items')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(DISTINCT vendor_id) > 1')
            ->get()
            ->count();

        $this->report("Checkout backfill: {$created} checkout(s) created (1:1), {$mixed} historical mixed-vendor order(s) left unsplit, {$normalised} 'pod' payment method row(s) normalised to 'pay_on_delivery'.");
    }

    public function down(): void
    {
        DB::table('orders')->whereNotNull('checkout_id')->update(['checkout_id' => null]);
        DB::table('checkouts')->delete();
    }

    private function report(string $line): void
    {
        Log::info($line);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, '  '.$line.PHP_EOL);
        }
    }
};
