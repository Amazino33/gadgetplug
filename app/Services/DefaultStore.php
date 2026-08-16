<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Vendor;

class DefaultStore
{
    public const NAME = 'Main Store';

    /**
     * Every vendor gets exactly one default store, created the moment the
     * vendor is — the same shape VendorRoles and FinancialAccounts already
     * seed. Phase 1's backfill gave one to every vendor that existed then;
     * this closes the other end, so a vendor can never exist without the
     * store its stock will hang off.
     *
     * Idempotent on (vendor, is_default): re-running it, or racing the Phase 1
     * backfill, returns the existing store rather than creating a second one.
     * The slug is left to the model's HasSlug + per-vendor extraScope, so it
     * is generated exactly as every other store's is.
     */
    public static function seedFor(Vendor $vendor): Store
    {
        $existing = Store::where('vendor_id', $vendor->id)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Store::create([
            'vendor_id'  => $vendor->id,
            'name'       => self::NAME,
            'is_default' => true,
            'is_active'  => true,
        ]);
    }
}
