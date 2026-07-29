<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;

function setUpForbiddenRedirectVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name' => 'Redirect Test Store',
    ]);

    VendorRoles::seedFor($vendor);

    // A team member, not the owner — the owner bypasses every permission check.
    $storekeeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$storekeeper->id]);

    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    return compact('owner', 'vendor', 'storekeeper');
}

it('redirects to the dashboard instead of showing a 403', function () {
    $ctx = setUpForbiddenRedirectVendor();

    // storekeeper has no manage_procurement, so Procurements is forbidden
    $response = $this->actingAs($ctx['storekeeper'])->get(
        route('filament.vendor.resources.procurements.index', ['tenant' => $ctx['vendor']->slug])
    );

    $response->assertRedirect(
        route('filament.vendor.home', ['tenant' => $ctx['vendor']->slug])
    );
});

it('leaves a page the role is allowed to open alone', function () {
    $ctx = setUpForbiddenRedirectVendor();

    // storekeeper does have manage_inventory, so this must not be bounced
    $response = $this->actingAs($ctx['storekeeper'])->get(
        route('filament.vendor.pages.blind-count', ['tenant' => $ctx['vendor']->slug])
    );

    $response->assertOk();
});
