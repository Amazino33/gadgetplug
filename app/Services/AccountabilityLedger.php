<?php

namespace App\Services;

use App\Models\AccountabilityLedgerEntry;
use App\Models\Product;
use App\Support\Accountability\FrozenLossSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// The only sanctioned way to write the accountability ledger, mirroring
// FinancialLedger::postEntry(). Every write is idempotent on a natural key so a
// double-submitted form, a retried job or a replayed webhook cannot charge
// somebody twice.
class AccountabilityLedger
{
    /**
     * Establish a shortage charge, priced and split at this moment and frozen.
     *
     * $naturalKey identifies the event, not the row — "one charge per case"
     * becomes charge:case:41. Callers pass it explicitly rather than having it
     * derived here, because Phase 2 has no case model yet and the caller is the
     * only thing that knows what makes this charge unique.
     */
    public function postCharge(
        int $vendorId,
        Product $product,
        int $shortageQty,
        string $naturalKey,
        ?int $storekeeperId = null,
        ?int $caseId = null,
        ?int $createdBy = null,
        ?string $note = null,
    ): AccountabilityLedgerEntry {
        if ($shortageQty === 0) {
            throw new InvalidArgumentException('A charge needs a non-zero shortage quantity.');
        }

        return $this->postChargeFromSnapshot(
            vendorId: $vendorId,
            snapshot: FrozenLossSnapshot::forProduct($product, $shortageQty),
            naturalKey: $naturalKey,
            storekeeperId: $storekeeperId,
            caseId: $caseId,
            createdBy: $createdBy,
            note: $note,
        );
    }

    /**
     * Post a charge from a snapshot that was frozen earlier.
     *
     * This is the form to use whenever the loss was established at one moment
     * and the charge is written at another — a case opened at count commit and
     * disposed days later, say. Passing the Product instead would re-price the
     * loss at today's figures, which defeats the entire point of freezing.
     */
    public function postChargeFromSnapshot(
        int $vendorId,
        FrozenLossSnapshot $snapshot,
        string $naturalKey,
        ?int $storekeeperId = null,
        ?int $caseId = null,
        ?int $createdBy = null,
        ?string $note = null,
        // Optional and defaulted, so existing callers are untouched. Charges
        // raised from a branch settlement need to say which branch, or a
        // per-branch view of what staff owe cannot see them — the same reason
        // postCashVariance carries these.
        ?int $storeId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): AccountabilityLedgerEntry {
        return $this->post(
            naturalKey: $naturalKey,
            attributes: array_merge($snapshot->toLedgerColumns(), [
                'vendor_id'      => $vendorId,
                'store_id'       => $storeId,
                'case_id'        => $caseId,
                'storekeeper_id' => $storekeeperId,
                'entry_type'     => 'charge',
                // Positive: a charge increases what is owed.
                'amount'         => $snapshot->chargeAmount,
                'source_type'    => $sourceType,
                'source_id'      => $sourceId,
                'note'           => $note,
                'created_by'     => $createdBy,
            ]),
        );
    }

    /**
     * Record money coming back — cash handed over, a salary deduction, or a
     * manual adjustment.
     */
    public function postRecovery(
        int $vendorId,
        string $type,
        float $amount,
        string $naturalKey,
        ?int $storekeeperId = null,
        ?int $caseId = null,
        ?int $createdBy = null,
        ?string $note = null,
    ): AccountabilityLedgerEntry {
        if (! in_array($type, AccountabilityLedgerEntry::RECOVERY_TYPES, true)) {
            throw new InvalidArgumentException("Invalid recovery type: {$type}");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('A recovery amount must be positive — the ledger applies the sign.');
        }

        return $this->post(
            naturalKey: $naturalKey,
            attributes: [
                'vendor_id'      => $vendorId,
                'case_id'        => $caseId,
                'storekeeper_id' => $storekeeperId,
                'entry_type'     => $type,
                // Negative: recoveries reduce what is owed. Callers pass a
                // positive figure and the sign is applied here, so no caller can
                // accidentally post a recovery that increases a debt.
                'amount'         => -1 * round($amount, 2),
                'note'           => $note,
                'created_by'     => $createdBy,
            ],
        );
    }

    /**
     * Stop pursuing the remainder and move it to company loss.
     *
     * The amount is computed from what is actually still outstanding rather than
     * supplied, so a conversion can never leave a residue or overshoot into a
     * negative balance. Returns null when nothing is outstanding — converting
     * a settled case is a no-op, not an error.
     */
    public function convertToWriteOff(
        int $vendorId,
        int $storekeeperId,
        string $naturalKey,
        ?int $caseId = null,
        ?int $createdBy = null,
        ?string $note = null,
    ): ?AccountabilityLedgerEntry {
        $outstanding = $caseId !== null
            ? AccountabilityLedgerEntry::outstandingForCase($caseId, $vendorId)
            : AccountabilityLedgerEntry::outstandingForStorekeeper($storekeeperId, $vendorId);

        if ($outstanding <= 0) {
            return null;
        }

        return $this->post(
            naturalKey: $naturalKey,
            attributes: [
                'vendor_id'      => $vendorId,
                'case_id'        => $caseId,
                'storekeeper_id' => $storekeeperId,
                'entry_type'     => 'writeoff_conversion',
                'amount'         => -1 * $outstanding,
                'note'           => $note,
                'created_by'     => $createdBy,
            ],
        );
    }

    /**
     * A cashier's end-of-day drawer difference.
     *
     * Lands in the same ledger as a stock shortage because it is the same
     * statement: a named member of staff is holding less than they should be. An
     * owner asking "what does this person owe me?" must get one answer, and a
     * second cash-only ledger beside this one would give two.
     *
     * The caller passes the variance with its own sign — negative is short — and
     * the entry type is chosen here, so no caller has to remember which way round
     * a shortage goes. A difference of nothing posts nothing: a zero-amount row
     * would say a cashier was accused of an amount, which is not what a clean
     * cash-up means.
     *
     * None of the stock-shaped columns apply. shortage_qty and the cost snapshots
     * stay null, which is what lets a cash-up review screen show these rows to a
     * manager who may not see product costs.
     */
    public function postCashVariance(
        int $vendorId,
        int $cashierId,
        float $variance,
        string $naturalKey,
        ?int $storeId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $createdBy = null,
        ?string $note = null,
    ): ?AccountabilityLedgerEntry {
        if (abs($variance) < 0.01) {
            return null;
        }

        $short = $variance < 0;

        return $this->post(
            naturalKey: $naturalKey,
            attributes: [
                'vendor_id'      => $vendorId,
                'store_id'       => $storeId,
                'storekeeper_id' => $cashierId,
                'entry_type'     => $short ? 'cash_shortage' : 'cash_overage',
                // A shortage increases what is owed, an overage reduces it. The
                // model enforces both signs on the way in.
                'amount'         => $short ? round(abs($variance), 2) : -1 * round($variance, 2),
                'source_type'    => $sourceType,
                'source_id'      => $sourceId,
                'note'           => $note,
                'created_by'     => $createdBy,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function post(string $naturalKey, array $attributes): AccountabilityLedgerEntry
    {
        if (trim($naturalKey) === '') {
            throw new InvalidArgumentException('A natural key is required — it is what makes the write idempotent.');
        }

        return DB::transaction(function () use ($naturalKey, $attributes) {
            $existing = AccountabilityLedgerEntry::where('idempotency_key', $naturalKey)->first();

            if ($existing) {
                return $existing;
            }

            try {
                return AccountabilityLedgerEntry::create(array_merge($attributes, [
                    'idempotency_key' => $naturalKey,
                    'created_at'      => now(),
                ]));
            } catch (QueryException $e) {
                // Lost a race between the check above and the insert. The unique
                // index is the real guard; this turns the collision back into the
                // idempotent answer the caller expected.
                $existing = AccountabilityLedgerEntry::where('idempotency_key', $naturalKey)->first();

                if ($existing) {
                    return $existing;
                }

                throw $e;
            }
        });
    }
}
