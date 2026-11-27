<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\Product;
use App\Models\StockTakeTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What a phone counted offline, told to the server afterwards.
 *
 * A batch is an ordered list of operations, each with an id the phone made:
 *
 *   set     this product in this aisle now stands at these units
 *   remove  this product in this aisle was taken back
 *
 * Applied in order, each on its own: one refused does not hold up the rest.
 * Each is recorded by its id, and a repeat is answered from that record — so
 * a batch whose answer was lost is sent again and nothing happens twice.
 *
 * Every rule a live save has applies here too: the task is the counter's and
 * still open, the aisle is the task's, halves only where the shop sells them,
 * a product already counted in a handed-in task is refused (decision 5).
 * Refused operations come back with the reason, for the phone's "Needs
 * attention" list.
 */
final class StockTakeSync
{
    public const SET    = 'set';
    public const REMOVE = 'remove';

    /** How far outside the task's life a phone's clock is still believed. */
    private const TOLERANCE_MINUTES = 5;

    public function __construct(private readonly StockTakeCounter $counter) {}

    /**
     * @param  array<int, array<string, mixed>>  $ops
     * @return array<int, array{id: string, status: string, reason?: string|null}>
     */
    public function apply(User $user, StockTakeTask $task, array $ops): array
    {
        $results = [];

        foreach ($ops as $op) {
            $results[] = $this->applyOne($user, $task->fresh(), $op);
        }

        return $results;
    }

    /** @param  array<string, mixed>  $op */
    private function applyOne(User $user, StockTakeTask $task, array $op): array
    {
        $id = (string) $op['id'];

        if ($done = $this->recorded($id, $task)) {
            return $done;
        }

        $type     = $op['type'] ?? self::SET;
        $entryId  = null;
        $status   = 'synced';
        $reason   = null;

        try {
            DB::transaction(function () use ($user, $task, $op, $type, &$entryId) {
                if ($type === self::REMOVE) {
                    $this->counter->removeFromPhone($user, $task, (int) $op['aisle_id'], (int) $op['product_id']);

                    return;
                }

                [$countedAt, $deviceAt, $flagged] = $this->believableTime($task, $op['counted_at'] ?? null, $op['device_counted_at'] ?? null);

                $entryId = $this->counter->setFromPhone(
                    $user,
                    $task,
                    Aisle::query()->where('vendor_id', $task->vendor_id)->findOrFail((int) $op['aisle_id']),
                    Product::query()->where('vendor_id', $task->vendor_id)->findOrFail((int) $op['product_id']),
                    $op['lines'] ?? [],
                    $countedAt,
                    $deviceAt,
                    $flagged,
                    (float) ($op['damaged'] ?? 0),
                    (float) ($op['expired'] ?? 0),
                )->id;
            });
        } catch (ValidationException $e) {
            $status = 'rejected';
            $reason = collect($e->errors())->flatten()->first();
        } catch (AuthorizationException $e) {
            $status = 'rejected';
            $reason = 'This task is no longer open for you. Ask the manager.';
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $status = 'rejected';
            $reason = 'That product or aisle is not part of this shop any more.';
        }

        return $this->record($id, $task, $user, $type, $status, $reason, $entryId);
    }

    /**
     * When the item was counted. The phone's time, corrected to the server's
     * clock, if it falls within the task's life (with a little slack either
     * side); otherwise the upload time, and the entry is flagged.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface|null, 2: bool}
     */
    public function believableTime(StockTakeTask $task, mixed $countedAt, mixed $deviceAt): array
    {
        $now    = CarbonImmutable::now();
        $device = $this->parse($deviceAt);
        $phone  = $this->parse($countedAt);

        $earliest = CarbonImmutable::parse($task->created_at)->subMinutes(self::TOLERANCE_MINUTES);
        $latest   = $now->addMinutes(self::TOLERANCE_MINUTES);

        if ($phone && $phone->betweenIncluded($earliest, $latest)) {
            // Never in the future: a moment that has not happened cannot have
            // been counted.
            return [$phone->greaterThan($now) ? $now : $phone, $device, false];
        }

        return [$now, $device, true];
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** The answer already given to an operation with this id, if any. */
    private function recorded(string $id, StockTakeTask $task): ?array
    {
        $row = DB::table('stock_take_sync_ops')->where('op_id', $id)->first();

        if (! $row) {
            return null;
        }

        // Someone else's id: never answer for another task.
        if ((int) $row->stock_take_task_id !== (int) $task->id) {
            return ['id' => $id, 'status' => 'rejected', 'reason' => 'Duplicate operation id.'];
        }

        return ['id' => $id, 'status' => $row->status, 'reason' => $row->reason];
    }

    private function record(string $id, StockTakeTask $task, User $user, string $type, string $status, ?string $reason, ?int $entryId): array
    {
        try {
            DB::table('stock_take_sync_ops')->insert([
                'vendor_id'          => $task->vendor_id,
                'stock_take_task_id' => $task->id,
                'op_id'              => $id,
                'type'               => $type,
                'status'             => $status,
                'reason'             => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'entry_id'           => $entryId,
                'user_id'            => $user->id,
                'created_at'         => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same batch, sent twice at once: the other one recorded it.
            return $this->recorded($id, $task);
        }

        return ['id' => $id, 'status' => $status, 'reason' => $reason];
    }
}
