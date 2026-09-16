<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\PosSession;
use App\Models\Store;
use App\Models\TillExpense;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Declare money spent out of the till.
 *
 * Recorded against the branch rather than the person, because the money is the
 * shop's either way and the next person to open the drawer inherits the gap.
 * Who declared it is kept alongside, since somebody has to be answerable for
 * the claim.
 */
class LogTillExpenseAction
{
    public function execute(
        User $loggedBy,
        Store|int $store,
        float $amount,
        string $reason,
        ?Carbon $spentAt = null,
    ): TillExpense {
        if ($amount <= 0) {
            throw new RuntimeException('An expense has to be for some money.');
        }

        if (blank($reason)) {
            throw new RuntimeException('Say what the money went on.');
        }

        $store = $store instanceof Store ? $store : Store::find($store);

        if (! $store) {
            throw new RuntimeException('That branch does not exist.');
        }

        $spentAt ??= now();

        // Spending cannot be declared into the future: a settlement statement
        // run today would not see it, and one run next week would find relief
        // appearing in a period that was already signed off.
        if ($spentAt->isFuture()) {
            throw new RuntimeException('An expense cannot be dated in the future.');
        }

        return TillExpense::create([
            'vendor_id'      => $store->vendor_id,
            'store_id'       => $store->id,
            'logged_by'      => $loggedBy->id,
            'amount'         => round($amount, 2),
            'reason'         => $reason,
            'spent_at'       => $spentAt,
            // Only so a cash-up can see what has already been declared and not
            // ask for the same money to be explained a second time.
            'pos_session_id' => $this->openSessionAt($store->id, $loggedBy->id),
        ]);
    }

    private function openSessionAt(int $storeId, int $userId): ?int
    {
        return PosSession::query()
            ->where('store_id', $storeId)
            ->where('cashier_id', $userId)
            ->whereNull('closed_at')
            ->value('id');
    }
}
