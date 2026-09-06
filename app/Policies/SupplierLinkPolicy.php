<?php

namespace App\Policies;

use App\Models\SupplierLink;
use App\Models\User;

/**
 * Who may open one vendor's catalogue to another.
 *
 * Super admins only, and not as a convenience — creating a link exposes a
 * vendor's entire catalogue, his wholesale prices included, to a different
 * vendor. A vendor granting themselves that access over somebody else's shop is
 * precisely the thing being prevented, so this cannot be delegated by a
 * permission a vendor owner could hold.
 *
 * Enforced here rather than by hiding the nav item: a hidden resource is still
 * reachable by URL, and the answer to "may I do this" has to be the same
 * wherever it is asked.
 */
class SupplierLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SupplierLink $link): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SupplierLink $link): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Never deleted.
     *
     * Listings published under a link and debts booked against it both point
     * here. Deactivate instead: that stops new listings and stops prices
     * resolving, while leaving what was published and what is owed intact.
     */
    public function delete(User $user, SupplierLink $link): bool
    {
        return false;
    }
}
