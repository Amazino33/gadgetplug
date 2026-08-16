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

    // Covers every path that flips the flag — the admin edit form's Toggle
    // and the vendors table's quick-toggle action both just call ->update(),
    // so one hook here logs both instead of duplicating the activity() call
    // at each call site.
    public function updated(Vendor $vendor): void
    {
        if (! $vendor->wasChanged('online_sales_enabled')) {
            return;
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($vendor)
            ->withProperties([
                'from' => $vendor->getOriginal('online_sales_enabled'),
                'to'   => $vendor->online_sales_enabled,
            ])
            ->log('Online sales ' . ($vendor->online_sales_enabled ? 'enabled' : 'disabled') . ' for vendor');
    }
}
