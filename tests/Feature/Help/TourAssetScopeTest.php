<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Where the tour bundle is allowed to load, and where it must not.
 *
 * The rule is not cosmetic: resources/js/app.js is shared with the storefront,
 * and the reason driver.js has its own Vite entry is so shoppers never download
 * a staff tour engine. A regression here is invisible in the UI and only shows
 * up on someone's data bill.
 */
function vendorPanelUser(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Tour Asset Store']);

    VendorRoles::seedFor($vendor);

    return compact('owner', 'vendor');
}

it('loads the tour bundle on the vendor panel', function () {
    ['owner' => $owner, 'vendor' => $vendor] = vendorPanelUser();

    $this->actingAs($owner)
        ->get("/plug/{$vendor->slug}/dashboard")
        ->assertOk()
        ->assertSee('vendor-tours', escape: false)
        ->assertSee('gp-tours-config', escape: false);
});

it('hands the browser this vendor and this person, not a global registry', function () {
    ['owner' => $owner, 'vendor' => $vendor] = vendorPanelUser();

    $html = $this->actingAs($owner)->get("/plug/{$vendor->slug}/dashboard")->getContent();

    preg_match('/<script type="application\/json" id="gp-tours-config">(.*?)<\/script>/s', $html, $m);

    expect($m)->not->toBeEmpty();

    $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

    expect($config['vendorId'])->toBe($vendor->id)
        ->and($config['seen'])->toBe([])
        ->and($config['tours'])->toHaveKeys(['record-procurement', 'add-product', 'daily-report'])
        // {vendor} has to be resolved server-side, or "Start" from the help
        // centre would navigate to a literal placeholder.
        ->and($config['tours']['record-procurement']['start_path'])->toBe("/plug/{$vendor->slug}");
});

it('keeps the tour bundle off the storefront', function () {
    ['owner' => $owner] = vendorPanelUser();

    $this->actingAs($owner)
        ->get('/')
        ->assertDontSee('gp-tours-config', escape: false);
});

it('keeps the tour bundle off the admin panel', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($admin)
        ->get('/admin')
        ->assertDontSee('gp-tours-config', escape: false);
});

it('gives every sidebar entry a stable data-tour hook', function () {
    ['owner' => $owner, 'vendor' => $vendor] = vendorPanelUser();

    $html = $this->actingAs($owner)->get("/plug/{$vendor->slug}/dashboard")->getContent();

    // These three are what the shipped tours point at. Derived from each item's
    // URL in the published sidebar view, so they survive a menu reorder --
    // which an nth-child selector would not.
    expect($html)
        ->toContain('data-tour="nav-procurements"')
        ->toContain('data-tour="nav-products"')
        ->toContain('data-tour="nav-help"');
});

it('shows every vendor the help centre in the menu', function () {
    ['owner' => $owner, 'vendor' => $vendor] = vendorPanelUser();

    $this->actingAs($owner)
        ->get("/plug/{$vendor->slug}/dashboard")
        ->assertOk()
        ->assertSee('Help &amp; Guides', escape: false);
});
