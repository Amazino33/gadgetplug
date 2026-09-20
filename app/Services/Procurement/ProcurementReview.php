<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\ProcurementItemCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;
use RuntimeException;

/**
 * The two-party review of a delivery: agree it, or say what is wrong with it.
 *
 * Every entry point goes through here — the panel, the till, and anything
 * added later — so the workflow has one home rather than one copy per screen.
 * The Filament page holds no rules at all; it renders what this class reports
 * and calls these two methods.
 *
 * The thing to understand about this feature: stock does not exist yet. A
 * recorded procurement writes no stock, no cost layer and no ledger entry —
 * ApproveProcurementAction does all of that, once, at the end. So the whole
 * disagreement plays out before anything is on a shelf, and agreement receives
 * the delivery correctly the first time instead of receiving it wrong and
 * adjusting afterwards. There is no correcting entry because there is nothing
 * yet to correct.
 */
class ProcurementReview
{
    public function __construct(
        private readonly ApproveProcurementAction $approveAction,
    ) {}

    /**
     * Both sides agree. Receive the goods at the figures they agreed on.
     */
    public function approve(Procurement $procurement, User $user, int|null $store = null): Procurement
    {
        if (! $user->can('approve', $procurement)) {
            throw new UnauthorizedException($this->refusal($procurement, $user));
        }

        return DB::transaction(function () use ($procurement, $user, $store) {
            // Re-read under a lock: two approvers on two tills reaching the
            // same delivery at the same moment must not both receive it.
            $row = Procurement::whereKey($procurement->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isOpen()) {
                throw new RuntimeException('That delivery has already been answered.');
            }

            // Receives at the verified figures, which are the recorded ones
            // when nobody corrected anything.
            $this->approveAction->execute($row, $store);

            $row->forceFill([
                'awaiting_user_id' => null,
                'approved_by'      => $user->id,
                'approved_at'      => now(),
            ])->save();

            return $row->fresh(['items.corrections']);
        });
    }

    /**
     * One side says a line is not what it claims.
     *
     * $lines is keyed by procurement item id, each holding a 'quantity' and/or
     * 'unit_cost'. Anything left out keeps whatever that line is currently
     * understood to be, so correcting one line of twelve means sending one
     * line, not restating the batch.
     *
     * @param  array<int, array{quantity?: int|string|null, unit_cost?: float|int|string|null}>  $lines
     */
    public function correct(Procurement $procurement, User $user, array $lines, ?string $note = null): Procurement
    {
        if (! $user->can('correct', $procurement)) {
            throw new UnauthorizedException($this->refusal($procurement, $user));
        }

        if ($lines === []) {
            throw new RuntimeException('Say which line is wrong.');
        }

        return DB::transaction(function () use ($procurement, $user, $lines, $note) {
            $row = Procurement::whereKey($procurement->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isOpen()) {
                throw new RuntimeException('That delivery has already been answered.');
            }

            $items   = $row->items()->with('corrections')->get()->keyBy('id');
            $changed = 0;

            foreach ($lines as $itemId => $values) {
                /** @var ProcurementItem|null $item */
                $item = $items->get((int) $itemId);

                if (! $item) {
                    throw new RuntimeException('That line is not part of this delivery.');
                }

                $quantity = array_key_exists('quantity', $values) && $values['quantity'] !== null
                    ? (int) $values['quantity']
                    : $item->verifiedQuantity();

                $unitCost = array_key_exists('unit_cost', $values) && $values['unit_cost'] !== null
                    ? round((float) $values['unit_cost'], 2)
                    : $item->verifiedUnitCost();

                if ($quantity < 0) {
                    throw new RuntimeException('A verified quantity cannot be negative.');
                }

                if ($unitCost < 0) {
                    throw new RuntimeException('A verified unit cost cannot be negative.');
                }

                // Already says exactly this. Writing it again would add a row
                // to the ledger that records no disagreement, and would hand
                // the batch back to somebody who has nothing to answer.
                if ($quantity === $item->verifiedQuantity()
                    && abs($unitCost - $item->verifiedUnitCost()) < 0.01) {
                    continue;
                }

                ProcurementItemCorrection::create([
                    'procurement_id'      => $row->id,
                    'procurement_item_id' => $item->id,
                    // Always the storekeeper's original figures, never the
                    // previous correction's. Variance on a line means
                    // "against what was written down at delivery", and
                    // chaining it against the last counter-offer instead would
                    // make the number mean something different on every pass
                    // of the argument.
                    'recorded_quantity'   => (int) $item->quantity,
                    'recorded_unit_cost'  => (float) $item->unit_cost,
                    'verified_quantity'   => $quantity,
                    'verified_unit_cost'  => $unitCost,
                    'corrected_by'        => $user->id,
                    'corrected_at'        => now(),
                    'note'                => $note,
                ]);

                $changed++;
            }

            if ($changed === 0) {
                throw new RuntimeException('Those are the figures already on this delivery.');
            }

            // Any correction to any line hands the whole batch back. A delivery
            // is agreed or it is not; there is no half-agreed batch where some
            // lines are settled and the rest are still in dispute.
            $row->forceFill([
                'status'           => Procurement::STATUS_CHANGES_REQUESTED,
                'awaiting_user_id' => $this->counterpartyOf($row, $user),
            ])->save();

            return $row->fresh(['items.corrections']);
        });
    }

    /**
     * Whose move it becomes.
     *
     * Strictly the other one of the two. The recorder hands it to the approver
     * who engaged with it; anyone else hands it to the recorder.
     */
    private function counterpartyOf(Procurement $procurement, User $user): int
    {
        if ((int) $user->id === (int) $procurement->created_by) {
            $partner = $procurement->reviewPartnerId();

            if ($partner === null) {
                // Only reachable if a recorder corrected a batch nobody had
                // touched, which the policy refuses. Guarded anyway, because
                // silently handing it back to themselves would strand it.
                throw new RuntimeException('Nobody has reviewed this delivery yet.');
            }

            return $partner;
        }

        return (int) $procurement->created_by;
    }

    /** Deliveries this person is personally holding up. */
    public function awaiting(User $user, int $vendorId): Builder
    {
        return Procurement::query()
            ->where('vendor_id', $vendorId)
            ->where('status', Procurement::STATUS_CHANGES_REQUESTED)
            ->where('awaiting_user_id', $user->id);
    }

    /** Deliveries nobody has picked up yet, which any eligible approver may. */
    public function unclaimed(int $vendorId): Builder
    {
        return Procurement::query()
            ->where('vendor_id', $vendorId)
            ->where('status', Procurement::STATUS_PENDING);
    }

    /**
     * Why the door is shut, in the words the person needs.
     *
     * A bare "not permitted" is useless here: the three reasons a button is
     * refused are completely different problems, and only one of them is about
     * permissions at all.
     */
    private function refusal(Procurement $procurement, User $user): string
    {
        if (! $procurement->isOpen()) {
            return 'That delivery has already been answered.';
        }

        if ($procurement->isChangesRequested() && ! $procurement->isAwaiting($user)) {
            return 'This delivery is back with the other party. You will get it again if they disagree.';
        }

        if ((int) $user->id === (int) $procurement->created_by) {
            return 'You recorded this delivery, so somebody else has to check it in.';
        }

        return 'You are not permitted to receive deliveries for this branch.';
    }
}
