<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\Order;
use App\Services\FinancialLedger;
use Illuminate\Support\Facades\Log;
use Throwable;

// The one place an order's sale turns into money in an account. Extracted from
// OrderObserver so the automatic path (a status change to paid/delivered) and
// the manual "Record Payment Received" recovery on the order page run the exact
// same logic — an order recovered by hand is recognized the way the observer
// would have recognized it, never by a second, looser definition.
//
// Posting is idempotent twice over: FinancialLedger::postEntry() returns the
// existing entry for the same (source, direction), and this refuses outright
// once revenue_recognized_at is set. Calling it again on a recognized order is
// a no-op, not a double count.
class RecognizeOrderRevenueAction
{
    // Returns null when revenue was recognized, or a machine-readable reason it
    // could not be. The observer only logs the reason; the UI turns it into a
    // message the owner can act on.
    public function execute(Order $order, ?string $channel = null): ?string
    {
        if ($order->isRevenueRecognized()) {
            return 'already_recognized';
        }

        // Paystack money always lands in the bank — there is no cash variant of
        // a card payment, so the caller's channel is ignored rather than trusted.
        $channel = $order->payment_method === 'paystack'
            ? 'bank_transfer'
            : ($channel ?? $order->payment_channel);

        if (! in_array($channel, ['cash', 'bank_transfer'], true)) {
            return 'no_channel';
        }

        $vendorId = $order->items()->value('vendor_id');

        if (! $vendorId) {
            return 'no_vendor';
        }

        $type = $channel === 'cash' ? 'cash' : 'bank';

        $account = FinancialAccount::where('vendor_id', $vendorId)->where('type', $type)->first();

        if (! $account) {
            Log::error("Revenue recognition skipped for order {$order->id}: no {$type} account found for vendor {$vendorId}.");

            return 'no_account';
        }

        try {
            FinancialLedger::postEntry(
                account: $account,
                direction: 'in',
                amount: (float) $order->total_amount,
                source: $order,
                description: "Revenue recognized — order {$order->reference} ({$channel})",
                createdBy: auth()->id(),
            );

            // Quietly — setting these must not re-trigger the observer (or any
            // other side effect) recursively.
            $order->updateQuietly([
                'revenue_recognized_at' => now(),
                'payment_channel'       => $channel,
            ]);
        } catch (Throwable $e) {
            Log::error("Revenue recognition failed for order {$order->id}: ".$e->getMessage());

            return 'failed';
        }

        return null;
    }
}
