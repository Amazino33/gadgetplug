<?php

namespace App\Observers;

use App\Models\Vendor;
use App\Services\DefaultStore;
use App\Services\FinancialAccounts;
use App\Services\VendorRoles;

class VendorObserver
{
    public function created(Vendor $vendor): void
    {
        VendorRoles::seedFor($vendor);
        FinancialAccounts::seedFor($vendor);
        // Deliberately no store_user rows for the owner — owner access runs
        // through vendors.user_id today and stays that way until Phase 3
        // decides what store membership means for an owner.
        DefaultStore::seedFor($vendor);
    }

    // Keeps the two block columns honest wherever the flag is flipped from —
    // the edit form, the table's quick action, or a console command later.
    // Clearing the reason on release matters: a stale reason left behind would
    // reappear verbatim the next time someone blocked the vendor from the
    // table action without retyping it.
    public function saving(Vendor $vendor): void
    {
        if (! $vendor->isDirty('dashboard_blocked')) {
            return;
        }

        if ($vendor->dashboard_blocked) {
            $vendor->dashboard_blocked_at = now();
        } else {
            $vendor->dashboard_blocked_at = null;
            $vendor->dashboard_blocked_reason = null;
        }
    }

    // Covers every path that flips either switch — the admin edit form's
    // Toggle and the vendors table's quick actions all just call ->update(),
    // so one hook here logs them instead of duplicating the activity() call
    // at each call site.
    public function updated(Vendor $vendor): void
    {
        if ($vendor->wasChanged('online_sales_enabled')) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($vendor)
                ->withProperties([
                    'from' => $vendor->getOriginal('online_sales_enabled'),
                    'to'   => $vendor->online_sales_enabled,
                ])
                ->log('Online sales ' . ($vendor->online_sales_enabled ? 'enabled' : 'disabled') . ' for vendor');
        }

        // Logged with the reason attached: a block is an account action someone
        // will be asked to justify later, and "who cut them off and why" has to
        // survive the next admin editing the reason field.
        if ($vendor->wasChanged('dashboard_blocked')) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($vendor)
                ->withProperties([
                    'from'   => (bool) $vendor->getOriginal('dashboard_blocked'),
                    'to'     => $vendor->dashboard_blocked,
                    'reason' => $vendor->dashboard_blocked_reason,
                ])
                ->log('Dashboard access ' . ($vendor->dashboard_blocked ? 'blocked' : 'restored') . ' for vendor');
        }
    }
}
