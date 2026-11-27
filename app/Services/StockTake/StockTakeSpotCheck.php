<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\StockTake;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Spot-checks: a small random share of the counts that looked fine, counted
 * again by someone else. A recount only happens when a count is off by more
 * than the threshold, so a wrong count that happened to look right is never
 * checked; this checks some of them, and the pair shows who was right.
 *
 * To the counter it is an ordinary recount (blind, like every count).
 */
final class StockTakeSpotCheck
{
    public const DEFAULT_PERCENT = 5;

    public function __construct(private readonly StockTakeApprover $approver) {}

    /** Counts that looked fine and are still waiting for a decision: what a spot-check picks from. */
    public function candidates(StockTake $stockTake): Collection
    {
        return StockTakeResult::query()
            ->where('stock_take_id', $stockTake->id)
            ->where('status', StockTakeResult::STATUS_PENDING)
            ->where('needs_recount', false)
            ->whereNull('recount_of_id')
            ->whereNull('recount_task_id')
            ->with('product:id,name')
            ->get();
    }

    /** How many a share of the candidates is: at least one when there are any. */
    public static function sizeOf(int $candidates, int $percent): int
    {
        if ($candidates === 0) {
            return 0;
        }

        return min($candidates, max(1, (int) ceil($candidates * $percent / 100)));
    }

    /**
     * Picks the share at random (never products the checker counted) and
     * sends them as recount tasks to the checker.
     *
     * @return Collection<int, StockTakeTask>
     *
     * @throws ValidationException
     */
    public function send(User $by, StockTake $stockTake, User $checker, ?string $dueDate, int $percent = self::DEFAULT_PERCENT): Collection
    {
        $percent = max(1, min(100, $percent));
        $pool    = $this->candidates($stockTake)
            ->reject(fn (StockTakeResult $r) => StockTakeAccess::countedBy($checker, $r))
            ->values();

        if ($pool->isEmpty()) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Nothing to spot-check: no count that looked fine is waiting, or ' . $checker->name . ' counted all of them.',
            ]);
        }

        $picked = $pool->shuffle()->take(self::sizeOf($pool->count(), $percent))->values();
        $tasks  = $this->approver->sendToRecount($by, $picked, $checker, $dueDate);

        StockTakeTask::query()->whereIn('id', $tasks->pluck('id'))->update(['is_spot_check' => true]);

        return $tasks;
    }
}
