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

        $snapshot = FrozenLossSnapshot::forProduct($product, $shortageQty);

        return $this->post(
            naturalKey: $naturalKey,
            attributes: array_merge($snapshot->toLedgerColumns(), [
                'vendor_id'      => $vendorId,
                'case_id'        => $caseId,
                'storekeeper_id' => $storekeeperId,
                'entry_type'     => 'charge',
                // Positive: a charge increases what is owed.
                'amount'         => $snapshot->chargeAmount,
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
