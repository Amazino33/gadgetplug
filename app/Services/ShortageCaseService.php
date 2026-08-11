<?php

namespace App\Services;

use App\Models\AccountabilityLedgerEntry;
use App\Models\AuditSession;
use App\Models\InventoryShortageCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

// Opens shortage cases at count commit and applies the owner's disposition.
// Authorization lives in InventoryShortageCasePolicy; this class assumes the
// caller has already passed it, and enforces only the rules that are about the
// data rather than the person.
class ShortageCaseService
{
    public function __construct(private AccountabilityLedger $ledger) {}

    /**
     * Open a case for a committed count line, freezing the loss as priced now.
     *
     * Returns null for a balanced line — a zero variance is not a shortage and
     * opening a case for it would bury the real ones in noise.
     *
     * Idempotent per count line: re-committing returns the existing case rather
     * than opening a second.
     */
    public function openForCountLine(AuditSession $line): ?InventoryShortageCase
    {
        $variance = $line->countedVariance();

        // Null means no baseline was recorded, so there is no defensible
        // variance to open a case about.
        if ($variance === null || $variance === 0) {
            return null;
        }

        $existing = InventoryShortageCase::where('count_line_id', $line->id)->first();

        if ($existing) {
            return $existing;
        }

        $product = $line->product;

        if (! $product) {
            return null;
        }

        $snapshot = \App\Support\Accountability\FrozenLossSnapshot::forProduct($product, $variance);

        try {
            return InventoryShortageCase::create(array_merge($snapshot->toLedgerColumns(), [
                'vendor_id'     => $line->vendor_id,
                'count_line_id' => $line->id,
                'product_id'    => $line->product_id,
                // No assigned-storekeeper concept exists in this data model, so
                // the case opens unattributed and the owner names someone.
                'charged_storekeeper_id' => null,
                'status'                 => 'pending_disposition',
            ]));
        } catch (QueryException $e) {
            // Lost a race on the unique index — return what the winner created.
            $existing = InventoryShortageCase::where('count_line_id', $line->id)->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /** Owner names (or changes) who carries the loss. */
    public function reassign(InventoryShortageCase $case, ?int $storekeeperId): InventoryShortageCase
    {
        if (! $case->awaitsDisposition()) {
            throw new RuntimeException('This case is already settled and cannot be reassigned.');
        }

        if ($storekeeperId !== null && ! $this->belongsToStore($storekeeperId, $case->vendor_id)) {
            throw new RuntimeException('That user is not a member of this store.');
        }

        $case->update(['charged_storekeeper_id' => $storekeeperId]);

        return $case->refresh();
    }

    /**
     * The company absorbs the loss. No storekeeper debt, and nothing touches
     * the accountability ledger — there is no charge to record.
     *
     * Only the cost is a real loss: the margin was never earned, so it was
     * never lost. The financial posting itself is Phase 4; this records the
     * decision and the figure it will post.
     */
    public function writeOff(InventoryShortageCase $case, int $disposedBy, string $reason): InventoryShortageCase
    {
        $this->guardDisposable($case, $reason);

        $case->update([
            'status'             => 'written_off',
            'disposed_by'        => $disposedBy,
            'disposed_at'        => now(),
            'disposition_reason' => $reason,
        ]);

        return $case->refresh();
    }

    /**
     * Charge the named storekeeper, posting exactly one ledger entry from the
     * frozen snapshot.
     */
    public function charge(InventoryShortageCase $case, int $disposedBy, string $reason): InventoryShortageCase
    {
        $this->guardDisposable($case, $reason);

        if ($case->charged_storekeeper_id === null) {
            throw new RuntimeException('Name who is accountable before charging this case.');
        }

        return DB::transaction(function () use ($case, $disposedBy, $reason) {
            // From the case's own frozen snapshot, never the product. A case can
            // sit in investigating for weeks; re-reading the product here would
            // charge at whatever it happens to cost the day the owner decides.
            $this->ledger->postChargeFromSnapshot(
                vendorId: $case->vendor_id,
                snapshot: $case->snapshot(),
                // Keyed on the case, so a double-submitted form posts once.
                naturalKey: "shortage_case:{$case->id}:charge",
                storekeeperId: $case->charged_storekeeper_id,
                caseId: $case->id,
                createdBy: $disposedBy,
                note: $reason,
            );

            $case->update([
                'status'             => 'charged',
                'disposed_by'        => $disposedBy,
                'disposed_at'        => now(),
                'disposition_reason' => $reason,
            ]);

            return $case->refresh();
        });
    }

    /** Park the case. No money moves; it can be charged or written off later. */
    public function investigate(InventoryShortageCase $case, int $disposedBy, ?string $reason = null): InventoryShortageCase
    {
        if (! $case->awaitsDisposition()) {
            throw new RuntimeException('This case is already settled.');
        }

        $case->update([
            'status'             => 'investigating',
            'disposed_by'        => $disposedBy,
            'disposed_at'        => now(),
            'disposition_reason' => $reason,
        ]);

        return $case->refresh();
    }

    /** What this case has actually put on the storekeeper, net of recoveries. */
    public function outstandingFor(InventoryShortageCase $case): float
    {
        return AccountabilityLedgerEntry::outstandingForCase($case->id, $case->vendor_id);
    }

    private function guardDisposable(InventoryShortageCase $case, string $reason): void
    {
        if (! $case->awaitsDisposition()) {
            throw new RuntimeException('This case is already settled.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for this disposition.');
        }
    }

    private function belongsToStore(int $userId, int $vendorId): bool
    {
        $user = \App\Models\User::find($userId);

        if (! $user) {
            return false;
        }

        return $user->ownedVendors()->where('id', $vendorId)->exists()
            || $user->memberVendors()->where('vendors.id', $vendorId)->exists();
    }
}
