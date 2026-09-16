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

/**
 * A branch with the two roles a handover needs kept apart: somebody who may
 * hand cash over, and somebody else who may receive it.
 */
function handoffContext(): array
{
    $vendor = cashVendor();
    $store  = $vendor->defaultStore;
    $owner  = User::find($vendor->user_id);

    cashRoles($vendor);

    $keeper = User::factory()->create();
    $keeper->stores()->attach($store->id);
    // Attached to the vendor as well as the branch, the way TeamMembers does
    // it. User::vendors() reads the membership pivot, not the store one, so a
    // fixture that only attaches stores is not a real team member.
    $vendor->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($vendor->id);
    $keeper->assignRole('storekeeper');

    $collector = User::factory()->create();
    $collector->stores()->attach($store->id);
    $vendor->users()->syncWithoutDetaching([$collector->id]);
    $collector->givePermissionTo('receive_cash');

    cashSale($vendor, $store, $keeper->id, ['total' => 50000]);

    return compact('vendor', 'store', 'owner', 'keeper', 'collector');
}

/** @return array{0: App\Models\CashSubmission, 1: string} */
function issueHandoff(array $ctx, float $amount = 50000): array
{
    $submission = app(App\Actions\Cash\SubmitCashAction::class)->execute(
        submitter: $ctx['keeper'],
        receiver:  null,
        store:     $ctx['store'],
        amount:    $amount,
    );

    return [$submission, App\Services\Cash\CashHandoffToken::issue($submission)];
}
