<?php

namespace App\Services;

use App\Events\Accountability\ShortageCharged;
use App\Events\Accountability\ShortageRecovered;
use App\Events\Accountability\ShortageWrittenOff;
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

        // Nothing was ever charged, so the whole cost is the loss and the whole
        // margin is forgone.
        ShortageWrittenOff::dispatch(
            $case->vendor_id,
            $case->id,
            $case->product_id,
            $case->charged_storekeeper_id,
            round((float) $case->cost_component, 2),
            round((float) $case->margin_component, 2),
            'disposition',
            now()->toDateString(),
        );

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

            ShortageCharged::dispatch(
                $case->vendor_id,
                $case->id,
                $case->product_id,
                (int) $case->charged_storekeeper_id,
                round((float) $case->cost_component, 2),
                round((float) $case->margin_component, 2),
                round((float) $case->charge_amount, 2),
                (bool) $case->price_fallback,
                now()->toDateString(),
            );

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

    /**
     * Record money coming back against a charged case.
     *
     * Partial is normal: several recoveries may sum toward one charge. The case
     * closes itself the moment nothing is left outstanding.
     *
     * @param  string  $eventKey  Identifies this recovery event, making the write idempotent.
     */
    public function recover(
        InventoryShortageCase $case,
        string $type,
        float $amount,
        string $eventKey,
        int $recordedBy,
        ?string $note = null,
    ): AccountabilityLedgerEntry {
        if ($case->status !== 'charged') {
            throw new RuntimeException('Only a charged case can be recovered against.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('A recovery amount must be greater than zero.');
        }

        return DB::transaction(function () use ($case, $type, $amount, $eventKey, $recordedBy, $note) {
            $outstandingBefore = $this->outstandingFor($case);

            // Guarded here rather than trusted to the UI. Recovering more than is
            // owed would drive the balance negative and turn a debt into a credit
            // the business never granted. Checked inside the transaction so two
            // concurrent part-payments cannot both pass on a stale reading.
            if (round($amount, 2) > round($outstandingBefore, 2)) {
                throw new RuntimeException(sprintf(
                    'That is more than is outstanding on this case (%s).',
                    '₦'.number_format($outstandingBefore, 2),
                ));
            }

            $entry = $this->ledger->postRecovery(
                vendorId: $case->vendor_id,
                type: $type,
                amount: $amount,
                naturalKey: $eventKey,
                storekeeperId: $case->charged_storekeeper_id,
                caseId: $case->id,
                createdBy: $recordedBy,
                note: $note,
            );

            $outstandingAfter = $this->outstandingFor($case);

            // Fully repaid — the case is settled, and settled is neither "still
            // charged" nor "written off": the company absorbed nothing.
            if (round($outstandingAfter, 2) <= 0.0) {
                $case->update(['status' => 'recovered']);
            }

            $split = $this->allocation($case);

            ShortageRecovered::dispatch(
                $case->vendor_id,
                $case->id,
                $case->product_id,
                $case->charged_storekeeper_id,
                $type,
                round($amount, 2),
                $split['recovered_cost'],
                $split['recovered_margin'],
                round(max($outstandingAfter, 0.0), 2),
                now()->toDateString(),
            );

            return $entry;
        });
    }

    /**
     * The owner gives up on the remainder: it moves to company loss.
     *
     * The loss is booked at cost only, net of whatever was recovered. Margin
     * never recovered is simply never recognised — it was never earned, so there
     * is nothing to write off.
     */
    public function convertToWriteOff(
        InventoryShortageCase $case,
        string $eventKey,
        int $recordedBy,
        string $reason,
    ): ?AccountabilityLedgerEntry {
        if ($case->status !== 'charged') {
            throw new RuntimeException('Only a charged case can be converted to a write-off.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to write off the remainder.');
        }

        return DB::transaction(function () use ($case, $eventKey, $recordedBy, $reason) {
            $entry = $this->ledger->convertToWriteOff(
                vendorId: $case->vendor_id,
                storekeeperId: (int) $case->charged_storekeeper_id,
                naturalKey: $eventKey,
                caseId: $case->id,
                createdBy: $recordedBy,
                note: $reason,
            );

            $split = $this->allocation($case);

            $case->update([
                'status'             => 'written_off',
                'disposed_by'        => $recordedBy,
                'disposed_at'        => now(),
                'disposition_reason' => $reason,
            ]);

            ShortageWrittenOff::dispatch(
                $case->vendor_id,
                $case->id,
                $case->product_id,
                $case->charged_storekeeper_id,
                $split['unrecovered_cost'],
                $split['unrecovered_margin'],
                'conversion',
                now()->toDateString(),
            );

            return $entry;
        });
    }

    /**
     * How recoveries on this case divide between cost and margin.
     *
     * Cost-first: money coming back repairs the real hole — replacing the goods
     * — before it touches margin that was never earned. Only once cost is whole
     * does the excess count as recovered margin.
     *
     * @return array{recovered_cost: float, recovered_margin: float, unrecovered_cost: float, unrecovered_margin: float}
     */
    public function allocation(InventoryShortageCase $case): array
    {
        $chargedCost   = (float) $case->cost_component;
        $chargedMargin = (float) $case->margin_component;

        // Sum of recovery rows only. writeoff_conversion also reduces the
        // balance, but it is the business absorbing a loss, not money returning.
        $recovered = abs((float) AccountabilityLedgerEntry::query()
            ->where('case_id', $case->id)
            ->whereIn('entry_type', AccountabilityLedgerEntry::RECOVERY_TYPES)
            ->sum('amount'));

        $recoveredCost   = min($recovered, $chargedCost);
        $recoveredMargin = max(0.0, $recovered - $chargedCost);

        return [
            'recovered_cost'     => round($recoveredCost, 2),
            'recovered_margin'   => round($recoveredMargin, 2),
            'unrecovered_cost'   => round(max(0.0, $chargedCost - $recoveredCost), 2),
            'unrecovered_margin' => round(max(0.0, $chargedMargin - $recoveredMargin), 2),
        ];
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
