<?php

namespace App\Services\FreePlan;

use App\Models\FreePlanSetting;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * The free plan's product cap. Checked when a product is created — from
 * Product::creating, so the form, an import, a duplicate and anything added
 * later are all held to it — and again up front by the Create screen so a
 * shop at its limit is told before filling the form in, not after.
 */
final class FreePlanLimits
{
    public static function productLimitReached(Vendor $vendor): bool
    {
        if (! $vendor->isFreePlan()) {
            return false;
        }

        return $vendor->products()->count() >= FreePlanSetting::productLimit();
    }

    public static function productLimitMessage(): string
    {
        return "You've reached the free plan limit of ".FreePlanSetting::productLimit().' products.';
    }

    /** Throws when this vendor may not add one more product. Paid vendors are never limited. */
    public static function assertCanAddProduct(?int $vendorId): void
    {
        if (! $vendorId) {
            return;
        }

        // Locked so two products saved at the same instant cannot both take
        // the last slot. Only holds inside a transaction (Filament's create
        // is one); outside of one it is an ordinary read.
        $vendor = Vendor::whereKey($vendorId)->lockForUpdate()->first();

        if ($vendor && self::productLimitReached($vendor)) {
            throw ValidationException::withMessages(['name' => self::productLimitMessage()]);
        }
    }
}
