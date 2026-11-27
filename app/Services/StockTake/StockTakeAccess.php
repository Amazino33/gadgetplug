<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;

/**
 * Who may do what in Stock Take, at which branch.
 *
 * A permission counts at a branch the person may work in, by the same rule as
 * every inventory screen (ActiveStore): the owner at every branch; in a shop
 * with one branch, every member; in a shop with several, the branches they are
 * assigned to. A manager trusted at Ikeja is not thereby trusted to correct
 * Lekki's stock — but a single-branch shop, where nobody was ever assigned to
 * anything, is not locked out of its own stock take.
 *
 *   manage_stock_takes   plan, assign, approve, reject, recount, close
 *   perform_stock_takes  carry out tasks assigned to you
 *   map_aisles           record which products sit in which aisle
 */
final class StockTakeAccess
{
    public const MANAGE  = 'manage_stock_takes';
    public const PERFORM = 'perform_stock_takes';
    public const MAP     = 'map_aisles';
    public const COUNTERS = 'manage_counters';

    public static function canManage(User $user, int $vendorId, int $storeId): bool
    {
        return self::allows($user, $vendorId, $storeId, self::MANAGE);
    }

    /**
     * Add and remove counters, reset their codes, show the sign-in QR. Its own
     * permission, so a supervisor can run the counters without being able to
     * approve counts or close a stock take.
     */
    public static function canManageCounters(User $user, int $vendorId, int $storeId): bool
    {
        return self::allows($user, $vendorId, $storeId, self::COUNTERS);
    }

    public static function canPerform(User $user, int $vendorId, int $storeId): bool
    {
        return self::allows($user, $vendorId, $storeId, self::PERFORM);
    }

    /** Map any aisle at a branch. */
    public static function canMap(User $user, int $vendorId, int $storeId): bool
    {
        return self::allows($user, $vendorId, $storeId, self::MAP);
    }

    /**
     * Map this one aisle: with map_aisles at its branch, or — without it —
     * through an open mapping task given to you that covers this aisle. That
     * is how an admin hands one storekeeper Aisles 3–5 for the day, and only
     * those, and only until the task is submitted or cancelled.
     */
    public static function canMapAisle(User $user, Aisle $aisle): bool
    {
        if (self::canMap($user, (int) $aisle->vendor_id, (int) $aisle->store_id)) {
            return true;
        }

        return self::mappingTaskFor($user, $aisle) !== null;
    }

    /** The open mapping task, given to this person, that covers this aisle. */
    public static function mappingTaskFor(User $user, Aisle $aisle): ?StockTakeTask
    {
        return StockTakeTask::query()
            ->where('vendor_id', $aisle->vendor_id)
            ->where('store_id', $aisle->store_id)
            ->where('type', StockTakeTask::TYPE_MAPPING)
            ->where('assigned_to', $user->id)
            ->open()
            ->whereHas('aisles', fn ($q) => $q->whereKey($aisle->id))
            ->first();
    }

    /**
     * Work on this task: it must be given to you, still open, and at a branch
     * you may work in. A mapping task needs nothing more — being given it is
     * the grant; a count or recount needs perform_stock_takes as well.
     */
    public static function canWorkOn(User $user, StockTakeTask $task): bool
    {
        if ((int) $task->assigned_to !== (int) $user->id || ! $task->isOpen()) {
            return false;
        }

        if (! self::reachesBranch($user, (int) $task->vendor_id, (int) $task->store_id)) {
            return false;
        }

        return $task->type === StockTakeTask::TYPE_MAPPING
            || self::canPerform($user, (int) $task->vendor_id, (int) $task->store_id);
    }

    /**
     * Decide on a result. Never one you counted yourself — except the owner,
     * who must then give a note (checked where the approval is made).
     */
    public static function canDecide(User $user, StockTakeResult $result): bool
    {
        if (! self::canManage($user, (int) $result->vendor_id, (int) $result->store_id)) {
            return false;
        }

        return ! self::countedBy($user, $result) || self::isOwner($user, (int) $result->vendor_id);
    }

    /** Whether deciding on this result needs the owner's override note. */
    public static function needsOverrideNote(User $user, StockTakeResult $result): bool
    {
        return self::countedBy($user, $result);
    }

    /**
     * Did this person enter any of the counts behind the result? For a recount
     * pair that is either count: the approver chooses between the two, so
     * having counted one of them is having counted what is being decided.
     */
    public static function countedBy(User $user, StockTakeResult $result): bool
    {
        $taskIds = array_filter([
            $result->stock_take_task_id,
            $result->recountOf?->stock_take_task_id,
            $result->recountResult?->stock_take_task_id,
        ]);

        return StockTakeEntry::query()
            ->whereIn('stock_take_task_id', $taskIds)
            ->where('product_id', $result->product_id)
            ->where('counted_by', $user->id)
            ->exists();
    }

    /** Belongs to the shop, and may work at this branch of it. */
    public static function reachesBranch(User $user, int $vendorId, int $storeId): bool
    {
        // Asked once per row on some screens: the shop and branch are looked up once per request.
        [$vendor, $hasBranch] = \App\Support\RequestMemo::get()->remember("branch:{$vendorId}:{$storeId}", function () use ($vendorId, $storeId) {
            $vendor = Vendor::find($vendorId);

            return [$vendor, $vendor !== null && $vendor->stores()->whereKey($storeId)->exists()];
        });

        // Switched off for this shop: nothing in Stock Take is open to anyone.
        if (! $vendor || ! $vendor->hasStockTake() || ! $hasBranch) {
            return false;
        }

        if ($user->isSuperAdmin() || $vendor->isOwner($user)) {
            return true;
        }

        return $user->memberVendors()->whereKey($vendorId)->exists()
            && ActiveStore::canAccess($vendor, $user, $storeId);
    }

    private static function allows(User $user, int $vendorId, int $storeId, string $permission): bool
    {
        if (! self::reachesBranch($user, $vendorId, $storeId)) {
            return false;
        }

        // The platform's super admin holds no role inside any shop, so the
        // shop's own permissions would refuse them everywhere; like the owner,
        // they may do everything here (when the shop has Stock Take on).
        return $user->isSuperAdmin() || $user->hasVendorPermission($vendorId, $permission);
    }

    private static function isOwner(User $user, int $vendorId): bool
    {
        return $user->ownedVendors()->where('id', $vendorId)->exists();
    }
}
