<?php

// Shared fixtures for the cash handover tests.
//
// In a helper file because Pest loads every test file into one global function
// namespace: helpers declared in a sibling test only exist when that sibling
// happens to be loaded too, so running a file on its own then fails.

use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Str;

function cashVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Cash Vendor '.uniqid(),
    ]);
}

/** A completed sale, exactly as the till writes one. */
function cashSale(Vendor $vendor, Store $store, int $cashierId, array $over = []): PosSale
{
    $total = $over['total'] ?? 10000;

    return PosSale::create(array_merge([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $vendor->id,
        'store_id'        => $store->id,
        'cashier_id'      => $cashierId,
        'subtotal'        => $total,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => $total,
        'payment_method'  => 'cash',
        'amount_tendered' => $total,
        'change_given'    => 0,
        'status'          => 'completed',
        'completed_at'    => now(),
    ], $over));
}

/**
 * Roles only carry permissions that already exist as rows, so the permission
 * seeder has to run first — the same order production needs, which is why the
 * two are done together here rather than assumed.
 */
function cashRoles(Vendor $vendor): void
{
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    App\Services\VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);
}
