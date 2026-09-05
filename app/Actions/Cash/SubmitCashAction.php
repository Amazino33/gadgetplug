<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\CashSubmission;
use App\Models\Store;
use App\Models\User;
use App\Services\Cash\CashDrawer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A storekeeper hands cash to somebody.
 *
 * Records what was actually handed over, not what should have been. A cashier
 * who is short must still be able to record the handover — refusing it would
 * leave the money moving with no record at all, which is worse than a recorded
 * shortfall and is exactly the leak this exists to close.
 *
 * The expected figure is snapshotted here rather than recomputed later, because
 * it is what the system said at the moment of the handover. Recomputing it
 * afterwards would quietly rewrite the accusation every time another sale
 * landed.
 */
class SubmitCashAction
{
    public function execute(
        User $submitter,
        User $receiver,
        Store|int $store,
        float $amount,
        ?string $reason = null,
    ): CashSubmission {
        if ($amount <= 0) {
            throw new RuntimeException('A handover has to be for some money.');
        }

        if ($submitter->id === $receiver->id) {
            // The entire value of this record is two names on it.
            throw new RuntimeException('Cash has to be handed to somebody else.');
        }

        $storeId = $store instanceof Store ? $store->id : $store;

        return DB::transaction(function () use ($submitter, $receiver, $storeId, $amount, $reason) {
            $store = Store::find($storeId);

            if (! $store) {
                throw new RuntimeException('That branch does not exist.');
            }

            // Read inside the transaction: two handovers recorded at once would
            // otherwise both snapshot the same expected figure and both look
            // correct, when only the first could be.
            $expected = CashDrawer::expectedFrom($store->vendor_id, $store->id, $submitter->id);
            $variance = round($amount - $expected, 2);

            // A difference needs an explanation from the person who knows what
            // happened, while they are still standing there.
            if (abs($variance) >= 0.01 && blank($reason)) {
                throw new RuntimeException(sprintf(
                    'This is %s %s than the %s expected. Say why before recording it.',
                    number_format(abs($variance), 2),
                    $variance < 0 ? 'less' : 'more',
                    number_format($expected, 2),
                ));
            }

            return CashSubmission::create([
                'vendor_id'       => $store->vendor_id,
                'store_id'        => $store->id,
                'submitted_by'    => $submitter->id,
                'received_by'     => $receiver->id,
                'amount'          => round($amount, 2),
                'expected_amount' => $expected,
                'reason'          => $reason,
                'status'          => CashSubmission::STATUS_PENDING,
            ]);
        });
    }
}
