<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\AisleProduct;
use App\Models\Product;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording which products sit in which aisle.
 *
 * Every entry point checks StockTakeAccess::canMapAisle itself, so no caller
 * can map an aisle the person may not touch (decision 4: a mapping task opens
 * only its own aisles, only while it is open).
 */
final class AisleMapper
{
    /**
     * Put a product on an aisle's map.
     *
     * @return bool  true if added, false if it was already there
     *
     * @throws AuthorizationException
     */
    public function map(User $user, Aisle $aisle, Product $product, ?StockTakeTask $task = null, string $source = AisleProduct::SOURCE_MAPPING): bool
    {
        $this->authorize($user, $aisle);
        $this->sameShop($aisle, $product);
        $task = $this->taskFor($user, $aisle, $task);

        try {
            $added = DB::transaction(function () use ($user, $aisle, $product, $source) {
                if ($aisle->products()->whereKey($product->id)->exists()) {
                    return false;
                }

                $aisle->products()->attach($product->id, [
                    'vendor_id' => $aisle->vendor_id,
                    'source'    => $source,
                    'mapped_by' => $user->id,
                    'mapped_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Two phones scanned the same product into the same aisle at once.
            $added = false;
        }

        $this->started($task);

        return $added;
    }

    /** @throws AuthorizationException */
    public function unmap(User $user, Aisle $aisle, Product $product): void
    {
        $this->authorize($user, $aisle);

        $aisle->products()->detach($product->id);
    }

    /**
     * Something on the shelf whose barcode the system does not know, for a
     * manager to create or link later.
     *
     * @throws AuthorizationException
     */
    public function recordUnknown(User $user, Aisle $aisle, string $name, ?string $barcode, ?UploadedFile $photo, ?StockTakeTask $task = null): StockTakeUnknownItem
    {
        $this->authorize($user, $aisle);
        $task = $this->taskFor($user, $aisle, $task);

        $item = StockTakeUnknownItem::create([
            'vendor_id'          => $aisle->vendor_id,
            'store_id'           => $aisle->store_id,
            'stock_take_task_id' => $task?->id,
            'aisle_id'           => $aisle->id,
            'barcode'            => $barcode !== null && trim($barcode) !== '' ? trim($barcode) : null,
            'name_entered'       => trim($name),
            'photo_path'         => $photo?->store("stock-take/unknown/{$aisle->vendor_id}", 'public'),
            'created_by'         => $user->id,
        ]);

        $this->started($task);

        return $item;
    }

    /**
     * The assignee says the mapping is done. Their access to the task's aisles
     * ends with it.
     *
     * @throws AuthorizationException
     */
    public function finish(User $user, StockTakeTask $task): void
    {
        if ($task->type !== StockTakeTask::TYPE_MAPPING || ! StockTakeAccess::canWorkOn($user, $task)) {
            throw new AuthorizationException('This mapping task is not open for you.');
        }

        $task->update([
            'status'       => StockTakeTask::STATUS_SUBMITTED,
            'started_at'   => $task->started_at ?? now(),
            'submitted_at' => now(),
        ]);
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, Aisle $aisle): void
    {
        if (! $aisle->is_active || ! StockTakeAccess::canMapAisle($user, $aisle)) {
            throw new AuthorizationException('You cannot map this aisle.');
        }
    }

    private function sameShop(Aisle $aisle, Product $product): void
    {
        if ((int) $product->vendor_id !== (int) $aisle->vendor_id) {
            throw ValidationException::withMessages(['product_id' => 'That product is not one of this shop\'s.']);
        }
    }

    /**
     * The mapping task this work belongs to: the one named, if it is the
     * person's, open and covers the aisle; otherwise the one that grants it.
     */
    private function taskFor(User $user, Aisle $aisle, ?StockTakeTask $task): ?StockTakeTask
    {
        if ($task
            && $task->type === StockTakeTask::TYPE_MAPPING
            && StockTakeAccess::canWorkOn($user, $task)
            && $task->aisles()->whereKey($aisle->id)->exists()) {
            return $task;
        }

        return StockTakeAccess::mappingTaskFor($user, $aisle);
    }

    private function started(?StockTakeTask $task): void
    {
        if ($task && $task->status === StockTakeTask::STATUS_ASSIGNED) {
            $task->update(['status' => StockTakeTask::STATUS_IN_PROGRESS, 'started_at' => now()]);
        }
    }
}
