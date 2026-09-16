<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleReversal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Withdraw a sale that has already been rung.
 *
 * The only thing in the codebase allowed to move a sale's status, and it never
 * does so without first writing the row that says why. Everything downstream —
 * expected cash, the settlement statement, the shortage a named person is asked
 * to explain — is a sum over sales in a state, so a status that can move without
 * leaving a record is a hole straight through the reconciliation.
 *
 * Stock, revenue and debt reversal stay with their callers. They were already
 * append-only and already correct; this is only about the sale row itself.
 */
class RecordSaleReversalAction
{
    /** The whole sale is withdrawn. */
    public function void(PosSale $sale, User $actor, string $reason): PosSaleReversal
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why the sale is being voided.');
        }

        return $this->record(
            sale:   $sale,
            actor:  $actor,
            type:   PosSaleReversal::TYPE_VOID,
            amount: (float) $sale->total,
            reason: $reason,
            expect: ['completed'],
        );
    }

    /** Goods came back. Some of the sale, or all of it, stops counting. */
    public function forReturn(PosSale $sale, User $actor, PosReturn $return, bool $fullyReturned, ?string $reason = null): PosSaleReversal
    {
        return $this->record(
            sale:   $sale,
            actor:  $actor,
            type:   $fullyReturned ? PosSaleReversal::TYPE_RETURN_FULL : PosSaleReversal::TYPE_RETURN_PARTIAL,
            amount: (float) $return->refund_amount,
            reason: $reason ?: "Return {$return->reference}",
            expect: ['completed', 'partial_refund'],
            source: $return,
        );
    }

    /**
     * @param  array<int, string>  $expect  States the sale may legitimately be in.
     */
    private function record(
        PosSale $sale,
        User $actor,
        string $type,
        float $amount,
        string $reason,
        array $expect,
        ?PosReturn $source = null,
    ): PosSaleReversal {
        return DB::transaction(function () use ($sale, $actor, $type, $amount, $reason, $expect, $source) {
            // Re-read under a lock: two tills voiding the same sale at once would
            // otherwise both pass the status check and both reverse the stock.
            $row = PosSale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($row->status, $expect, true)) {
                throw new RuntimeException(sprintf(
                    'That sale is %s, so it cannot be %s.',
                    $row->status,
                    $type === PosSaleReversal::TYPE_VOID ? 'voided' : 'returned against',
                ));
            }

            $to = PosSaleReversal::STATUS_FOR_TYPE[$type];

            $reversal = PosSaleReversal::create([
                'vendor_id'    => $row->vendor_id,
                'store_id'     => $row->store_id,
                'pos_sale_id'  => $row->id,
                'type'         => $type,
                'from_status'  => $row->status,
                'to_status'    => $to,
                'amount'       => round($amount, 2),
                'reason'       => $reason,
                'source_type'  => $source ? $source->getMorphClass() : null,
                'source_id'    => $source?->getKey(),
                'performed_by' => $actor->id,
            ]);

            // Written after the reversal, never before: if this throws, the
            // record of why still stands and the mirror can be repaired from it.
            PosSale::mirroringReversal(fn () => $row->update(['status' => $to]));

            // Brought into line as already-saved rather than set as a pending
            // change: leaving the caller's copy dirty would make the next
            // innocent save() on it look like an attempt to edit a rung sale.
            $sale->setRawAttributes(array_merge($sale->getAttributes(), ['status' => $to]), sync: true);

            return $reversal;
        });
    }
}
