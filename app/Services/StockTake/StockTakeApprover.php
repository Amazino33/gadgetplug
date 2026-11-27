<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\AisleProduct;
use App\Models\AuditSession;
use App\Models\Product;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Services\Inventory\StockAtTime;
use App\Services\Inventory\StoreStock;
use App\Support\Quantity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deciding on stock take results: approve (stock corrected), reject (stock
 * untouched), or send to a recount by someone else.
 *
 * Approval applies the variance, never the counted number. The shop has kept
 * selling since the count, so:
 *
 *   system  = stock at counted_at, worked out again now under the stock lock
 *             (any till sale that synced since the result was built is in it)
 *   change  = counted − system
 *   stock   = stock now + change
 *
 * 50 on the books, 48 counted at 10:00, 10 sold by 16:00 → 40 + (−2) = 38.
 * Setting stock to 48 would undo the ten sales.
 *
 * A product has one applied result per stock take. A recount replaces the
 * count it re-checks — whichever of the two the approver chooses is applied,
 * the other is rejected — and never adds to it.
 */
final class StockTakeApprover
{
    public function __construct(private readonly AdjustStockAction $adjustStock) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function approve(User $by, StockTakeResult $result, ?string $note = null): StockTakeResult
    {
        $this->mustDecide($by, $result, $note);

        $approved = DB::transaction(function () use ($by, $result, $note) {
            $result = StockTakeResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();
            $this->mustBeApprovable($result);

            $other = StockTakeResult::query()
                ->where('stock_take_id', $result->stock_take_id)
                ->where('product_id', $result->product_id)
                ->where('status', StockTakeResult::STATUS_APPROVED)
                ->whereKeyNot($result->id)
                ->exists();

            if ($other) {
                throw ValidationException::withMessages(['result' => 'This product already has an approved count in this stock take.']);
            }

            // The same locks a sale takes, in the same order, before anything
            // is read: no sale can land between reading the shelf and fixing it.
            $product = Product::query()->whereKey($result->product_id)->lockForUpdate()->firstOrFail();
            StoreStock::lockedRow($product, (int) $result->store_id);

            $system  = StockAtTime::at($product->id, (int) $result->store_id, $result->counted_at);
            // Damaged and expired are on the shelf too: the count is everything
            // there, and they are written off apart below, so stock ends at the
            // good count and the loss is recorded for what it is.
            $bad     = Quantity::add($result->damaged_qty_base ?? 0, $result->expired_qty_base ?? 0);
            $present = Quantity::add($result->total_counted_base, $bad);
            $change  = Quantity::sub($present, $system);
            $unit    = $result->unit_cost;
            $counter = $this->counterOf($result);

            $line = AuditSession::create([
                'vendor_id'              => $result->vendor_id,
                'stock_take_task_id'     => $result->stock_take_task_id,
                'product_id'             => $product->id,
                'system_quantity'        => $system,
                'storekeeper_a_id'       => $counter,
                'count_a'                => $present,
                'manager_id'             => $by->id,
                'manager_override_count' => $present,
                // Decided already: neither status offers the line actions that
                // would correct stock a second time from the audit screens.
                'status'                 => (float) $change == 0.0 ? 'verified' : 'resolved_by_override',
                'loss_value'             => $change < 0 && $unit !== null ? round(abs($change) * $unit, 2) : 0,
                'note'                   => $note !== null && trim($note) !== '' ? trim($note) : null,
                'stock_take_result_id'   => $result->id,
            ]);

            $ledger = null;

            if ((float) $change != 0.0) {
                $ledger = $this->adjustStock->execute(
                    productId: $product->id,
                    quantityChanged: $change,
                    transactionType: 'count_adjustment',
                    userId: $by->id,
                    reference: $this->reference($result),
                    description: sprintf(
                        'Stock take %s — counted %s, system %s at %s',
                        $change < 0 ? 'shortfall' : 'overage',
                        Quantity::format($present),
                        Quantity::format($system),
                        $result->counted_at->timezone('Africa/Lagos')->format('D j M, g:ia'),
                    ),
                    auditSessionId: $line->id,
                    store: (int) $result->store_id,
                    // Truth is truth: a count may take the books below zero
                    // when the till sold ahead of the shelf.
                    allowNegative: true,
                );
            }

            $writeoff = null;

            if ((float) $bad > 0) {
                $writeoff = $this->adjustStock->execute(
                    productId: $product->id,
                    quantityChanged: -$bad,
                    transactionType: 'stock_adjustment',
                    userId: $by->id,
                    reference: $this->reference($result),
                    description: 'Stock take write-off — ' . implode(', ', array_filter([
                        (float) $result->damaged_qty_base > 0 ? Quantity::format($result->damaged_qty_base) . ' damaged' : null,
                        (float) $result->expired_qty_base > 0 ? Quantity::format($result->expired_qty_base) . ' expired' : null,
                    ])),
                    auditSessionId: $line->id,
                    store: (int) $result->store_id,
                    allowNegative: true,
                );
            }

            $result->update([
                'status'                 => StockTakeResult::STATUS_APPROVED,
                'approved_by'            => $by->id,
                'approved_at'            => now(),
                'writeoff_ledger_id'     => $writeoff?->id,
                'writeoff_value'         => (float) $bad > 0 && $unit !== null ? round($bad * $unit, 2) : null,
                'override_note'          => $line->note,
                'applied_system_qty'     => $system,
                'applied_adjustment_qty' => $change,
                'inventory_ledger_id'    => $ledger?->id,
                'audit_session_id'       => $line->id,
            ]);

            $this->mapWhereFound($result);
            $this->rejectTheOtherCount($by, $result);

            return $result;
        });

        $this->settleTasks($approved);

        return $approved->fresh();
    }

    /** @throws AuthorizationException|ValidationException */
    public function reject(User $by, StockTakeResult $result, ?string $note = null): void
    {
        $this->mustDecide($by, $result, $note);

        DB::transaction(function () use ($by, $result, $note) {
            $result = StockTakeResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();
            $this->mustBeApprovable($result);

            $result->update([
                'status'        => StockTakeResult::STATUS_REJECTED,
                'approved_by'   => $by->id,
                'approved_at'   => now(),
                'override_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);
        });

        $this->settleTasks($result->fresh());
    }

    /**
     * Send results to be counted again, by someone who counted none of them.
     * One recount task per original task.
     *
     * @param  Collection<int, StockTakeResult>  $results
     * @return Collection<int, StockTakeTask>
     *
     * @throws AuthorizationException|ValidationException
     */
    public function sendToRecount(User $by, Collection $results, User $assignee, ?string $dueDate): Collection
    {
        if ($results->isEmpty()) {
            throw ValidationException::withMessages(['results' => 'Pick what to recount.']);
        }

        foreach ($results as $result) {
            if (! StockTakeAccess::canManage($by, (int) $result->vendor_id, (int) $result->store_id)) {
                throw new AuthorizationException('You cannot run stock takes at this branch.');
            }

            if ($result->status !== StockTakeResult::STATUS_PENDING) {
                throw ValidationException::withMessages(['results' => "{$result->product->name} has already been decided."]);
            }

            if (StockTakeAccess::countedBy($assignee, $result)) {
                throw ValidationException::withMessages([
                    'assigned_to' => "{$assignee->name} counted {$result->product->name}. A recount must be done by someone else.",
                ]);
            }
        }

        $first = $results->first();

        if (! StockTakeAccess::canPerform($assignee, (int) $first->vendor_id, (int) $first->store_id)) {
            throw ValidationException::withMessages(['assigned_to' => "{$assignee->name} cannot count at this branch."]);
        }

        $tasks = DB::transaction(function () use ($by, $results, $assignee, $dueDate) {
            return $results->groupBy('stock_take_task_id')->map(function (Collection $group, $parentId) use ($by, $assignee, $dueDate) {
                $first = $group->first();

                $task = StockTakeTask::create([
                    'vendor_id'      => $first->vendor_id,
                    'store_id'       => $first->store_id,
                    'stock_take_id'  => $first->stock_take_id,
                    'type'           => StockTakeTask::TYPE_RECOUNT,
                    'parent_task_id' => (int) $parentId,
                    'assigned_to'    => $assignee->id,
                    'due_date'       => $dueDate,
                    'created_by'     => $by->id,
                ]);

                $task->products()->attach($group->pluck('product_id')->unique()->all());

                foreach ($group as $result) {
                    StockTakeResult::query()->whereKey($result->id)->lockForUpdate()->first()?->update([
                        'status'          => StockTakeResult::STATUS_SENT_TO_RECOUNT,
                        'recount_task_id' => $task->id,
                    ]);
                }

                return $task;
            })->values();
        });

        foreach ($results->pluck('stock_take_task_id')->unique() as $taskId) {
            StockTakeResults::settle(StockTakeTask::findOrFail($taskId));
        }

        return $tasks;
    }

    /**
     * Approve every pending result not flagged for recount — those this person
     * may decide. The ones they counted themselves are left for someone else.
     *
     * @return array{approved: int, left: int}
     */
    public function approveAllUnflagged(User $by, int $stockTakeId): array
    {
        $approved = 0;
        $left     = 0;

        $results = StockTakeResult::query()
            ->where('stock_take_id', $stockTakeId)
            ->pending()
            ->where('needs_recount', false)
            ->orderBy('product_id')
            ->get();

        foreach ($results as $result) {
            if (! StockTakeAccess::canDecide($by, $result) || StockTakeAccess::needsOverrideNote($by, $result)) {
                $left++;

                continue;
            }

            try {
                $this->approve($by, $result);
                $approved++;
            } catch (ValidationException) {
                $left++;
            }
        }

        return ['approved' => $approved, 'left' => $left];
    }

    /** Marked on the audit line of a correction the system posted by itself. */
    public const AUTO_RECHECK_NOTE = 'Auto re-check (late till sale)';

    /**
     * Re-check by hand: the fallback when the automatic run (see
     * LateSaleRecheck) did not manage to post the correction.
     *
     * @return int|float  the extra change posted (0 if none was needed)
     *
     * @throws AuthorizationException|ValidationException
     */
    public function recheck(User $by, StockTakeResult $result): int|float
    {
        if (! StockTakeAccess::canManage($by, (int) $result->vendor_id, (int) $result->store_id)) {
            throw new AuthorizationException('You cannot run stock takes at this branch.');
        }

        return $this->correctLateSales($result, $by, 'Re-check (late till sale)');
    }

    /**
     * A till sale rung before the count arrived after the count was approved:
     * the approval measured the shelf against books without it, so its
     * deduction has now landed a second time. Work stock at the moment of
     * counting out again and post the difference, under the stock lock, as an
     * audit line of its own linked to the result. Runs whether or not the
     * stock take is still open: the books must hold either way.
     *
     * @return int|float  the extra change posted (0 if none was needed)
     *
     * @throws ValidationException
     */
    public function correctLateSales(StockTakeResult $result, ?User $by, string $note): int|float
    {
        return DB::transaction(function () use ($result, $by, $note) {
            $result = StockTakeResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if ($result->status !== StockTakeResult::STATUS_APPROVED || $result->applied_system_qty === null) {
                throw ValidationException::withMessages(['result' => 'Only an approved count can be re-checked.']);
            }

            $product = Product::query()->whereKey($result->product_id)->lockForUpdate()->firstOrFail();
            StoreStock::lockedRow($product, (int) $result->store_id);

            // Stock at counted_at, worked out again. The approval's own
            // correction (and any earlier re-check) came after counted_at, so
            // StockAtTime rightly leaves them out; the late sale is now in.
            $system = StockAtTime::at($product->id, (int) $result->store_id, $result->counted_at);
            $extra  = Quantity::sub($result->applied_system_qty, $system);

            if ((float) $extra == 0.0) {
                $result->update(['recheck_needed_at' => null]);

                return 0;
            }

            $line = AuditSession::create([
                'vendor_id'              => $result->vendor_id,
                'stock_take_task_id'     => $result->stock_take_task_id,
                'stock_take_result_id'   => $result->id,
                'product_id'             => $product->id,
                'system_quantity'        => $system,
                'storekeeper_a_id'       => $this->counterOf($result),
                'count_a'                => $result->total_counted_base,
                'manager_id'             => $by?->id,
                'manager_override_count' => $result->total_counted_base,
                'status'                 => 'resolved_by_override',
                // This correction's own shortfall, priced as the approval was.
                'loss_value'             => $extra < 0 && $result->unit_cost !== null ? round(abs($extra) * $result->unit_cost, 2) : 0,
                'note'                   => $note,
            ]);

            $this->adjustStock->execute(
                productId: $product->id,
                quantityChanged: $extra,
                transactionType: 'count_adjustment',
                userId: $by?->id,
                reference: $this->reference($result),
                description: "{$note} — a till sale from before the count arrived after it was approved",
                auditSessionId: $line->id,
                store: (int) $result->store_id,
                allowNegative: true,
            );

            $result->update([
                'applied_system_qty'     => $system,
                'applied_adjustment_qty' => Quantity::add($result->applied_adjustment_qty ?? 0, $extra),
                'recheck_needed_at'      => null,
            ]);

            return $extra;
        });
    }

    /**
     * Whether a re-check would change anything: stock at the moment of
     * counting, worked out now, differs from what the approval used.
     */
    public static function needsRecheck(StockTakeResult $result): bool
    {
        if ($result->status !== StockTakeResult::STATUS_APPROVED || $result->applied_system_qty === null) {
            return false;
        }

        $now = StockAtTime::at((int) $result->product_id, (int) $result->store_id, $result->counted_at);

        return (float) Quantity::sub($result->applied_system_qty, $now) != 0.0;
    }

    /**
     * The one of a recount pair that is not chosen. Rejected, with stock
     * untouched — the approved one replaces it.
     */
    private function rejectTheOtherCount(User $by, StockTakeResult $approved): void
    {
        $others = StockTakeResult::query()
            ->where('stock_take_id', $approved->stock_take_id)
            ->where('product_id', $approved->product_id)
            ->whereKeyNot($approved->id)
            ->whereIn('status', [StockTakeResult::STATUS_PENDING, StockTakeResult::STATUS_SENT_TO_RECOUNT])
            ->where(fn ($q) => $q->whereKey($approved->recount_of_id ?? 0)->orWhere('recount_of_id', $approved->id))
            ->get();

        foreach ($others as $other) {
            $other->update([
                'status'      => StockTakeResult::STATUS_REJECTED,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
        }
    }

    /** Found on an aisle that did not list it: now it does. */
    private function mapWhereFound(StockTakeResult $result): void
    {
        $aisles = StockTakeEntry::query()
            ->where('stock_take_task_id', $result->stock_take_task_id)
            ->where('product_id', $result->product_id)
            ->where('is_unlisted', true)
            ->pluck('aisle_id');

        foreach ($aisles as $aisleId) {
            $exists = DB::table('aisle_products')->where('aisle_id', $aisleId)->where('product_id', $result->product_id)->exists();

            if (! $exists) {
                DB::table('aisle_products')->insert([
                    'vendor_id'  => $result->vendor_id,
                    'aisle_id'   => $aisleId,
                    'product_id' => $result->product_id,
                    'source'     => AisleProduct::SOURCE_COUNT,
                    'mapped_by'  => $result->approved_by,
                    'mapped_at'  => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Status of every task the decision touched. */
    private function settleTasks(StockTakeResult $result): void
    {
        $taskIds = StockTakeResult::query()
            ->where('stock_take_id', $result->stock_take_id)
            ->where('product_id', $result->product_id)
            ->pluck('stock_take_task_id')
            ->unique();

        foreach ($taskIds as $taskId) {
            StockTakeResults::settle(StockTakeTask::findOrFail($taskId));
        }
    }

    /** @throws AuthorizationException|ValidationException */
    private function mustDecide(User $by, StockTakeResult $result, ?string $note): void
    {
        if (! StockTakeAccess::canDecide($by, $result)) {
            throw new AuthorizationException(StockTakeAccess::countedBy($by, $result)
                ? 'You counted this product, so someone else must decide on it.'
                : 'You cannot decide on stock take results at this branch.');
        }

        if (StockTakeAccess::needsOverrideNote($by, $result) && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages(['note' => 'You counted this product yourself. Say why you are deciding on it anyway.']);
        }
    }

    private function mustBeApprovable(StockTakeResult $result): void
    {
        $ok = $result->status === StockTakeResult::STATUS_PENDING
            // Sent to recount, but the recount ended without it: the original
            // can still be decided.
            || ($result->status === StockTakeResult::STATUS_SENT_TO_RECOUNT
                && $result->recountTask
                && ! $result->recountTask->isOpen()
                && ! $result->recountResult()->where('status', StockTakeResult::STATUS_APPROVED)->exists());

        if (! $ok) {
            throw ValidationException::withMessages(['result' => match ($result->status) {
                StockTakeResult::STATUS_SENT_TO_RECOUNT => 'This is being recounted. Wait for the recount to be submitted.',
                default                                 => 'This has already been decided.',
            }]);
        }
    }

    /** Who entered the latest count behind the result. */
    private function counterOf(StockTakeResult $result): int
    {
        return (int) StockTakeEntry::query()
            ->where('stock_take_task_id', $result->stock_take_task_id)
            ->where('product_id', $result->product_id)
            ->latest('counted_at')
            ->value('counted_by');
    }

    private function reference(StockTakeResult $result): string
    {
        return ($result->stockTake?->reference ?? 'ST') . '/T' . $result->stock_take_task_id;
    }
}
