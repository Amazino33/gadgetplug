<?php

namespace App\Services;

use App\Models\AuditSession;
use App\Models\StockAccountabilityEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

// The only sanctioned way to write a stock accountability entry, mirroring
// FinancialLedger::postEntry(). Everything that attributes a shortage goes
// through here so the rules live in one place rather than in each Filament
// action that happens to need them.
class StockAccountabilityLedger
{
    /**
     * Attribute a counted variance to a storekeeper (or to nobody).
     *
     * Idempotent on (audit_session, disposition): calling twice for the same
     * count line returns the existing entry rather than charging someone
     * twice. The unique index is the real guard under concurrency; this
     * pre-check just avoids the exception in the ordinary case.
     */
    public function attribute(
        AuditSession $audit,
        string $disposition,
        ?int $storekeeperId,
        int $resolvedBy,
        ?string $reasonCode = null,
        ?string $note = null,
    ): StockAccountabilityEntry {
        if (! in_array($disposition, ['written_off', 'recoverable', 'recorded'], true)) {
            throw new InvalidArgumentException("Invalid disposition: {$disposition}");
        }

        // An unresolved discrepancy has no agreed figure — A and B are still in
        // dispute — so there is nothing to hold anyone to yet.
        if (! $audit->isSettled()) {
            throw new RuntimeException('This count is not settled yet, so its variance cannot be attributed.');
        }

        // Without a recorded baseline there is no defensible variance, and a
        // number invented here would be attributed to a real person. Rows that
        // predate the snapshot column are refused rather than guessed at.
        if ($audit->system_quantity === null) {
            throw new RuntimeException(
                'This count has no recorded system quantity, so its variance cannot be attributed. '
                .'It predates baseline capture.'
            );
        }

        if ($storekeeperId !== null && ! $this->isStorekeeperOf($storekeeperId, $audit->vendor_id)) {
            throw new RuntimeException('That user is not a member of this store.');
        }

        return DB::transaction(function () use ($audit, $disposition, $storekeeperId, $resolvedBy, $reasonCode, $note) {
            $existing = StockAccountabilityEntry::where('audit_session_id', $audit->id)
                ->where('disposition', $disposition)
                ->first();

            if ($existing) {
                return $existing;
            }

            $variance = $audit->countedQuantity() - (int) $audit->system_quantity;

            // Cost is frozen here. cost_price moves with every restock, and an
            // amount someone owes must not change because stock was replenished
            // at a different price next week.
            $unitCost = $audit->product?->cost_price !== null
                ? (float) $audit->product->cost_price
                : null;

            // 'recorded' is explicitly a no-money disposition, so it carries no
            // amount even when a cost is known.
            $amount = ($disposition === 'recorded' || $unitCost === null)
                ? 0.0
                : abs($variance) * $unitCost;

            return StockAccountabilityEntry::create([
                'vendor_id'         => $audit->vendor_id,
                'product_id'        => $audit->product_id,
                'audit_session_id'  => $audit->id,
                'storekeeper_id'    => $storekeeperId,
                'quantity_variance' => $variance,
                'unit_cost'         => $unitCost,
                'amount'            => $amount,
                'disposition'       => $disposition,
                'reason_code'       => $reasonCode,
                'note'              => $note,
                'resolved_by'       => $resolvedBy,
                'occurred_at'       => now()->toDateString(),
            ]);
        });
    }

    /**
     * Cancel an entry by writing its opposite. The original stays exactly as it
     * was — this is the only way to undo an attribution.
     */
    public function reverse(StockAccountabilityEntry $entry, int $resolvedBy, ?string $note = null): StockAccountabilityEntry
    {
        if ($entry->disposition === 'reversal') {
            throw new RuntimeException('A reversal cannot itself be reversed.');
        }

        return DB::transaction(function () use ($entry, $resolvedBy, $note) {
            $existing = StockAccountabilityEntry::where('audit_session_id', $entry->audit_session_id)
                ->where('disposition', 'reversal')
                ->first();

            if ($existing) {
                return $existing;
            }

            return StockAccountabilityEntry::create([
                'vendor_id'         => $entry->vendor_id,
                'product_id'        => $entry->product_id,
                'audit_session_id'  => $entry->audit_session_id,
                'storekeeper_id'    => $entry->storekeeper_id,
                'quantity_variance' => -$entry->quantity_variance,
                'unit_cost'         => $entry->unit_cost,
                'amount'            => $entry->amount,
                'disposition'       => 'reversal',
                'reason_code'       => $entry->reason_code,
                'note'              => $note,
                'reverses_entry_id' => $entry->id,
                'resolved_by'       => $resolvedBy,
                'occurred_at'       => now()->toDateString(),
            ]);
        });
    }

    /**
     * What a storekeeper currently owes: recoverable entries, less anything
     * reversed. Derived on read, never stored — same discipline as
     * FinancialAccount::balance().
     */
    public function outstandingFor(int $storekeeperId, int $vendorId): float
    {
        $base = StockAccountabilityEntry::where('vendor_id', $vendorId)
            ->where('storekeeper_id', $storekeeperId);

        $owed = (clone $base)->where('disposition', 'recoverable')->sum('amount');

        // A reversal only cancels money if what it reversed was recoverable —
        // reversing a write-off never created a debt to begin with.
        $reversed = (clone $base)
            ->where('disposition', 'reversal')
            ->whereHas('reversedEntry', fn ($q) => $q->where('disposition', 'recoverable'))
            ->sum('amount');

        return round((float) $owed - (float) $reversed, 2);
    }

    /** Total absorbed as business loss over a period, for reporting. */
    public function writtenOffTotal(int $vendorId, ?string $from = null, ?string $to = null): float
    {
        $base = StockAccountabilityEntry::where('vendor_id', $vendorId)
            ->when($from, fn ($q) => $q->whereDate('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('occurred_at', '<=', $to));

        $off = (clone $base)->where('disposition', 'written_off')->sum('amount');

        $reversed = (clone $base)
            ->where('disposition', 'reversal')
            ->whereHas('reversedEntry', fn ($q) => $q->where('disposition', 'written_off'))
            ->sum('amount');

        return round((float) $off - (float) $reversed, 2);
    }

    private function isStorekeeperOf(int $userId, int $vendorId): bool
    {
        $user = User::find($userId);

        if (! $user) {
            return false;
        }

        return $user->ownedVendors()->where('id', $vendorId)->exists()
            || $user->memberVendors()->where('vendors.id', $vendorId)->exists();
    }
}
