<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\PosSale;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\Moments\CashSalesTodayReached;

/**
 * The till. Not a Filament page (it is the POS app at /sales-point/{slug}), so
 * the access rule is the same one the panel's "Sales Point Terminal" menu item
 * uses rather than a page's canAccess(). Because this feature owns the route,
 * any tip may also send its button to Sales Point under this same rule.
 */
class SalesPointFeature extends Feature
{
    public function key(): string
    {
        return 'sales_point';
    }

    public function defaultTitle(): string
    {
        return 'Sell at the till';
    }

    public function defaultDescription(): string
    {
        return 'Ring up sales on your phone or computer. It keeps working when the internet drops.';
    }

    public function destinationRoute(): ?string
    {
        return 'pos.vendor';
    }

    public function canAccess(User $user, Vendor $vendor): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // The till is not part of the free plan (PosAuthController refuses it).
        return ! $vendor->isFreePlan() && $user->hasVendorPermission($vendor->id, 'access_pos');
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => PosSale::query(),
                userColumns: ['cashier_id' => 'created_at'],
                model: PosSale::class,
            ),
        ];
    }

    public function moments(): array
    {
        return [CashSalesTodayReached::class];
    }
}
