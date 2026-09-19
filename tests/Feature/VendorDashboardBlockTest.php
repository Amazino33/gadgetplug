<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// The panel's POS nav item calls hasPermissionTo('access_pos') while building
// the sidebar, which throws on a bare database. Seeded here so a render that
// reaches the panel is a real render rather than a 500 that any assertion
// about the page would misread.
beforeEach(fn () => (new Database\Seeders\VendorPermissionsSeeder())->run());

// The block is a money lever: an admin turns it on when a vendor owes the
// platform or breaks the terms, and the vendor loses both the back office and
// the till until it is settled. These check that it actually closes both doors,
// that support can still get in, and that lifting it puts everything back.

// A real panel page, not the /plug/{slug} entry point. That entry point only
// 302s to the dashboard, and following the redirect in-process leaves the
// Filament tenant singleton populated for the second request — which is enough
// to make the block look enforced in a test while a browser, whose every
// request starts clean, sails straight past it.
function panelPage(Vendor $vendor): string
{
    return '/plug/'.$vendor->slug.'/dashboard';
}

function blockedVendor(array $attributes = []): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(array_merge([
        'user_id'                  => $owner->id,
        'name'                     => 'Blocked Store '.uniqid(),
        'dashboard_blocked'        => true,
        'dashboard_blocked_reason' => 'Outstanding platform commission for August.',
    ], $attributes));

    return compact('owner', 'vendor');
}

it('shows the lockout page instead of the panel for a blocked owner', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor();

    $this->actingAs($owner)
        ->get(panelPage($vendor))
        ->assertStatus(403)
        ->assertSee('Account access suspended')
        ->assertSee('Outstanding platform commission for August.');
});

it('blocks the whole team, not just the owner', function () {
    ['vendor' => $vendor] = blockedVendor();

    $staff = User::factory()->create();
    $vendor->users()->attach($staff->id);

    $this->actingAs($staff)
        ->get(panelPage($vendor))
        ->assertStatus(403)
        ->assertSee('Account access suspended');
});

it('falls back to a generic reason when the admin left one out', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor(['dashboard_blocked_reason' => null]);

    $this->actingAs($owner)
        ->get(panelPage($vendor))
        ->assertStatus(403)
        ->assertSee('pending a payment or terms review');
});

it('lets a super admin through a blocked vendor so support can still work', function () {
    ['vendor' => $vendor] = blockedVendor();

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    // Asserted on the status rather than the page text: what matters is that
    // the block middleware let them past, and the panel it renders afterwards
    // is somebody else's test to make.
    expect($this->actingAs($admin)->get(panelPage($vendor))->status())->not->toBe(403);
});

it('leaves an unblocked vendor alone', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor(['dashboard_blocked' => false]);

    expect($this->actingAs($owner)->get(panelPage($vendor))->status())->not->toBe(403);
});

it('closes the POS terminal page too', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor();

    $this->actingAs($owner)
        ->get('/pos/'.$vendor->slug)
        ->assertStatus(403)
        ->assertSee('Account access suspended');
});

it('refuses a till PIN login while the account is blocked', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor();

    $owner->forceFill(['pos_pin' => Hash::make('1234')])->save();

    $this->postJson('/api/pos/auth/login', [
        'vendor_id' => $vendor->id,
        'pin'       => '1234',
    ])
        ->assertStatus(403)
        ->assertJson([
            'blocked' => true,
            'message' => 'Outstanding platform commission for August.',
        ]);
});

it('stops a till token that was issued before the block', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor(['dashboard_blocked' => false]);

    Sanctum::actingAs($owner, ['pos']);

    // Trading normally first, so the refusal below is the block and not a
    // route that was broken to begin with.
    $this->getJson('/api/pos/products?vendor_id='.$vendor->id)->assertOk();

    $vendor->update(['dashboard_blocked' => true, 'dashboard_blocked_reason' => 'Terms breach.']);

    $this->getJson('/api/pos/products?vendor_id='.$vendor->id)
        ->assertStatus(403)
        ->assertJson(['blocked' => true, 'message' => 'Terms breach.']);
});

it('closes the procurement wizard, which sits outside the panel', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor();

    $this->actingAs($owner)
        ->get('/procurement/create')
        ->assertStatus(403)
        ->assertSee('Account access suspended');
});

it('stamps the block time and clears the reason when access is restored', function () {
    ['vendor' => $vendor] = blockedVendor();

    expect($vendor->fresh()->dashboard_blocked_at)->not->toBeNull();

    $vendor->update(['dashboard_blocked' => false]);

    expect($vendor->fresh()->dashboard_blocked_at)->toBeNull()
        ->and($vendor->fresh()->dashboard_blocked_reason)->toBeNull();
});

it('restores both the panel and the till when the block is lifted', function () {
    ['owner' => $owner, 'vendor' => $vendor] = blockedVendor();

    $vendor->update(['dashboard_blocked' => false]);

    expect($this->actingAs($owner)->get(panelPage($vendor))->status())->not->toBe(403);
    $this->actingAs($owner)->get('/pos/'.$vendor->slug)->assertOk();
});
