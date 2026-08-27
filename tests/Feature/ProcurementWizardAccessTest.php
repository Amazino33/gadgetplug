<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user with no vendor at all is refused instead of falling back to an arbitrary vendor', function () {
    // Another vendor exists — this is exactly the scenario the old
    // Vendor::first() fallback would have silently leaked into.
    $otherOwner = User::factory()->create();
    Vendor::create(['user_id' => $otherOwner->id, 'name' => 'Someone Elses Store']);

    $stranger = User::factory()->create();

    // Denial now redirects instead of dead-ending on a 403 page (see the
    // handler in bootstrap/app.php). What matters is unchanged: they are sent
    // away and never see the wizard.
    $response = $this->actingAs($stranger)->get(route('procurement.create'));

    $response->assertRedirect(route('account.profile'));

    $this->actingAs($stranger)
        ->followingRedirects()
        ->get(route('procurement.create'))
        ->assertDontSee('Procurement');
});

test('a vendor member without manage_procurement cannot open the procurement wizard', function () {
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Procurement Test Store']);
    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    // Same denial, redirected rather than 403'd — the storekeeper is turned
    // away from the wizard and lands somewhere they can actually use.
    $this->actingAs($storekeeper)
        ->get(route('procurement.create'))
        ->assertRedirect(route('account.profile'));
});

test('the vendor owner can open the procurement wizard', function () {
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Owner Procurement Store']);
    VendorRoles::seedFor($vendor);
    Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);

    $this->actingAs($owner)
        ->get(route('procurement.create'))
        ->assertOk();
});

test('a role granted manage_procurement can reach the items step and see cost price there', function () {
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Manager Procurement Store']);
    VendorRoles::seedFor($vendor);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);
    Product::create([
        'vendor_id' => $vendor->id, 'category_id' => \App\Models\Category::create(['name' => 'Cat'])->id,
        'name' => 'Procured Widget', 'price' => 5000, 'cost_price' => 3000, 'status' => 'published',
    ]);

    $manager = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($vendor->id);
    $manager->assignRole('store_admin'); // store_admin has manage_procurement by default

    $this->actingAs($manager)
        ->withSession([
            'procurement.supplier_id' => $supplier->id,
            'procurement.store_id'    => $vendor->defaultStore->id,
        ])
        ->get(route('procurement.items'))
        ->assertOk()
        ->assertSee('Procured Widget');
});
