<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Store;
use App\Models\User;

// Which branch a POS terminal is standing in.
//
// The panel's ActiveStore cannot answer this: the till authenticates as a
// cashier over a token, with no Filament tenant and no panel session, so
// there is no active store to read. What the till does have is the cashier,
// so the branch is derived from where that cashier is assigned to work.
//
// One assignment is the common and unambiguous case — a cashier stands at one
// counter. More than one, or none, falls back to the vendor's default store,
// which is where POS stock has always moved from, so the till keeps working
// rather than refusing a sale over a configuration question.
class TillStore
{
    public static function resolve(User $cashier, int $vendorId): ?int
    {
        $assigned = $cashier->storesForVendor($vendorId);

        if ($assigned->count() === 1) {
            return (int) $assigned->first()->id;
        }

        $default = Store::query()
            ->where('vendor_id', $vendorId)
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        return $default === null ? null : (int) $default;
    }
}
