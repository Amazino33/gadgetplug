<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FinancialLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money the business spent, recorded once and posted once.
 *
 * Extracted from the panel's create screen so the till can record an expense the
 * same way rather than growing its own copy. Two implementations of "spend
 * money" is two chances for one of them to forget the ledger, and an expense
 * that never reaches the accounts is money the books think the shop still has.
 *
 * Both movements happen together: the expense exists and the account is lighter.
 * Either alone is a lie.
 */
class RecordExpenseAction
{
    public function execute(
        int $vendorId,
        string $category,
        float $amount,
        User $recordedBy,
        ?FinancialAccount $account = null,
        ?string $description = null,
        ?int $storeId = null,
        ?string $incurredAt = null,
    ): Expense {
        if (! array_key_exists($category, Expense::CATEGORIES)) {
            throw new RuntimeException('That is not a kind of expense this shop records.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('An expense has to be for some money.');
        }

        return DB::transaction(function () use (
            $vendorId, $category, $amount, $recordedBy, $account, $description, $storeId, $incurredAt
        ) {
            $expense = Expense::create([
                'vendor_id'            => $vendorId,
                'store_id'             => $storeId,
                'category'             => $category,
                'amount'               => round($amount, 2),
                'description'          => $description,
                'incurred_at'          => $incurredAt ?? now()->toDateString(),
                'financial_account_id' => $account?->id,
                'created_by'           => $recordedBy->id,
            ]);

            // Choosing an account is what "records this as paid" — the same rule
            // the panel form follows. An expense with no account is a note that
            // money will be spent, not a record that it has been.
            if ($account) {
                $this->postToLedger($expense, $account, $recordedBy);
            }

            activity()->causedBy($recordedBy)
                ->performedOn($expense)
                ->withProperties(['category' => $category, 'amount' => round($amount, 2), 'store_id' => $storeId])
                ->tap(fn ($a) => $a->vendor_id = $vendorId)
                ->log('Recorded expense');

            return $expense->refresh();
        });
    }

    /**
     * Take the money out of the account it was paid from.
     *
     * Public and separate so the panel's create screen posts through exactly
     * this code rather than its own copy of it. A second implementation is a
     * second chance to forget the ledger, and an expense that never reaches the
     * accounts is money the books believe the shop still has.
     */
    public function postToLedger(Expense $expense, FinancialAccount $account, User $by): void
    {
        if ($expense->isPosted()) {
            return;
        }

        FinancialLedger::postEntry(
            account: $account,
            direction: 'out',
            amount: (float) $expense->amount,
            source: $expense,
            description: 'Expense — '.$expense->category.($expense->description ? ": {$expense->description}" : ''),
            occurredAt: $expense->incurred_at,
            createdBy: $by->id,
            storeId: $expense->store_id,
        );

        $expense->update(['posted_at' => now()]);
    }

    /**
     * The drawer, for a till paying something out of it.
     *
     * Returns null rather than inventing an account: an expense that cannot be
     * posted anywhere must not silently become an unposted note while the money
     * has genuinely left the drawer.
     */
    public static function cashAccountFor(int $vendorId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('vendor_id', $vendorId)
            ->where('type', 'cash')
            ->where('is_active', true)
            ->first();
    }
}
