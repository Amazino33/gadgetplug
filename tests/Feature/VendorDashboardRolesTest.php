<?php

use App\Models\User;
use App\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Filament\Facades\Filament;
use Livewire\Volt\Volt;
use Livewire\Livewire;
use App\Livewire\Vendor\HomeDashboard;

function setupDashboardTestVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'name' => 'Dashboard Test Vendor',
        'slug' => 'dashboard-test-vendor-' . uniqid(),
        'online_sales_enabled' => true,
        'user_id' => $owner->id,
    ]);
    
    // Attach owner
    $vendor->users()->attach($owner->id);

    // Create staff
    $staff = User::factory()->create();
    $vendor->users()->attach($staff->id);
    
    // Give staff some basic permissions but NOT owner-level permissions
    // In actual app, staff has roles. We'll manually attach 'access_pos' permission to user-vendor relation if Spatie is used, or via Role.
    // The hasVendorPermission method usually checks `$user->hasPermissionTo(...)` or `$user->roles`.
    // Let's create a role for the vendor and give it to the staff.
    $role = Role::firstOrCreate(['name' => 'storekeeper', 'guard_name' => 'web']);
    if (!Permission::where('name', 'access_pos')->exists()) {
        Permission::firstOrCreate(['name' => 'access_pos', 'guard_name' => 'web']);
    }
    $role->givePermissionTo('access_pos');
    
    // Since roles are team-scoped in GadgetPlug, we set the tenant first
    setPermissionsTeamId($vendor->id);
    $staff->assignRole($role);
    
    return compact('owner', 'staff', 'vendor');
}

test('an owner user sees the owner hero set on the dashboard', function () {
    $data = setupDashboardTestVendor();
    
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
    
    $component = Livewire::test(HomeDashboard::class);
    
    // Owner heroes
    $component->assertSee('Add Stock')
        ->assertSee('Change Price')
        ->assertSee('Add Product')
        ->assertSee('View Sales');
        
    // Owner sees record sale as well, but we can't easily assert if it's "hero" via HTML parsing simply,
    // but we can assert it's present.
    $component->assertSee('Record Sale');
});

test('a staff user sees the staff hero set and is blocked from owner-only tiles', function () {
    $data = setupDashboardTestVendor();
    
    test()->actingAs($data['staff']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
    
    $component = Livewire::test(HomeDashboard::class);
    
    // Staff heroes
    $component->assertSee('Record Sale');
        
    // Staff should NOT see owner tiles
    $component->assertDontSee('Add Stock')
        ->assertDontSee('Change Price')
        ->assertDontSee('Add Product')
        ->assertDontSee('View Sales');
});

test('a staff user is blocked at the route level from owner-only pages', function () {
    $data = setupDashboardTestVendor();
    
    test()->actingAs($data['staff']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
    
    // Change Price is blocked
    $this->get(\App\Filament\Vendor\Pages\ChangePrice::getUrl())
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});
