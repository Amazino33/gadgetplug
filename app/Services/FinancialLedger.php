<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

// The only sanctioned way to write to financial_ledger_entries. Every future
// prompt (order recognition, procurement, expenses) posts through here rather
// than calling FinancialLedgerEntry::create() directly, so idempotency is
// enforced in one place instead of trusted to every caller individually.
class FinancialLedger
{
    public static function postEntry(
        FinancialAccount $account,
        string $direction,
        float $amount,
        ?Model $source = null,
        ?string $description = null,
        ?CarbonInterface $occurredAt = null,
        ?int $createdBy = null,
        ?int $storeId = null,
    ): FinancialLedgerEntry {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException("Invalid ledger direction: {$direction}");
        }

        if ($amount < 0) {
            throw new InvalidArgumentException('Ledger amount must be non-negative — direction carries the sign.');
        }

        $sourceType = $source?->getMorphClass();
        $sourceId   = $source?->getKey();

        // Keyed on direction too, not just the source — the same source can
        // legitimately need both an 'out' and an 'in' entry (e.g. an Order
        // carries delivery cost 'out' and revenue recognition 'in'). Without
        // this, the second postEntry() call for a source would match the
        // first entry regardless of direction and silently return the wrong
        // row instead of posting the real, different movement.
        if ($source) {
            $existing = FinancialLedgerEntry::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('direction', $direction)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            $entry = FinancialLedgerEntry::create([
                'financial_account_id' => $account->id,
                'direction'            => $direction,
                'amount'               => $amount,
                'source_type'          => $sourceType,
                'source_id'            => $sourceId,
                'description'          => $description,
                'occurred_at'          => $occurredAt?->toDateString() ?? now()->toDateString(),
                'created_by'           => $createdBy,
                // Which branch the money actually moved through. Optional and
                // last so every existing caller keeps working unchanged; they
                // pass nothing and keep writing null, which is honest — a
                // vendor-level entry has no branch to claim.
                'store_id'             => $storeId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a concurrent post for the same source +
            // direction — the other call's row is the real entry, not an
            // error here.
            return FinancialLedgerEntry::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('direction', $direction)
                ->firstOrFail();
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($entry)
            ->withProperties([
                'financial_account_id' => $account->id,
                'direction'            => $direction,
                'amount'               => $amount,
            ])
            ->log('Ledger entry posted');

        return $entry;
    }
}
