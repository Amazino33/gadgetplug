<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;

/**
 * Does this person hold this permission at THIS branch.
 *
 * Spatie's teams feature is pinned to the vendor here, so a permission granted
 * anywhere is granted everywhere under that vendor. That is fine for most of
 * what it guards, and wrong for cash: a collector trusted to receive takings at
 * one branch has no business confirming a handover at another, and the whole
 * value of the confirmation is that it comes from somebody who was actually
 * standing there.
 *
 * So the branch dimension is composed on top rather than pushed into Spatie:
 * vendor-wide permission AND an explicit assignment to the branch.
 *
 * No "unassigned means every branch" carve-out, deliberately — that convention
 * exists elsewhere in the codebase for read-only views, where showing too much
 * is a nuisance. Here it would silently hand every unassigned staff member the
 * power to close out cash anywhere, which is the opposite of what this is for.
 */
class StorePermission
{
    public static function allows(User $user, int $vendorId, int $storeId, string|array $permission): bool
    {
        // Ownership is not a permission and is never store-scoped: the person
        // the money ultimately belongs to can receive it at any of their own
        // branches.
        if ($user->isSuperAdmin() || $user->ownedVendors()->where('id', $vendorId)->exists()) {
            return true;
        }

        if (! $user->hasVendorPermission($vendorId, $permission)) {
            return false;
        }

        return $user->storesForVendor($vendorId)
            ->contains(fn ($store) => (int) $store->id === $storeId);
    }

    /**
     * The same question, phrased for a guard clause.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function authorize(User $user, int $vendorId, int $storeId, string|array $permission): void
    {
        if (! self::allows($user, $vendorId, $storeId, $permission)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You are not permitted to do that at this branch.'
            );
        }
    }
}
