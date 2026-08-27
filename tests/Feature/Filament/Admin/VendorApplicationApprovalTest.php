<?php

use App\Filament\Resources\VendorApplications\Pages\ListVendorApplications;
use App\Filament\Resources\VendorApplications\Pages\ViewVendorApplication;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorApplication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsAdminForVendorApps(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

function pendingVendorApplication(string $storeName = 'Wins Gadgets'): VendorApplication
{
    return VendorApplication::create([
        'user_id'       => User::factory()->create()->id,
        'store_name'    => $storeName,
        'business_type' => 'Individual Reseller',
        'description'   => 'Sells phones and accessories.',
        'whatsapp'      => '08162386631',
        'status'        => 'pending',
    ]);
}

// Approving is how a vendor gets onto the platform at all. It threw "Unknown
// column 'role'" because vendor_users lost that column when per-vendor roles
// moved to Spatie, and both approve paths still wrote to it.
test('approving an application creates the vendor and attaches the applicant', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication();

    Livewire::test(ListVendorApplications::class)
        ->callTableAction('approve', $application, data: ['admin_notes' => 'Welcome onboard']);

    $vendor = Vendor::where('name', 'Wins Gadgets')->first();

    expect($vendor)->not->toBeNull()
        ->and($vendor->user_id)->toBe($application->user_id)
        ->and((bool) $vendor->is_verified)->toBeTrue()
        // Ownership is vendors.user_id; the pivot only records membership
        ->and($vendor->users()->where('users.id', $application->user_id)->exists())->toBeTrue();

    expect($application->fresh()->status)->toBe('approved')
        ->and($application->fresh()->admin_notes)->toBe('Welcome onboard');
});

test('the new vendor is usable straight away', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Ready Store');

    Livewire::test(ListVendorApplications::class)
        ->callTableAction('approve', $application, data: ['admin_notes' => null]);

    $vendor = Vendor::where('name', 'Ready Store')->first();

    // The observer seeds these on creation; without them the vendor lands in a
    // panel with no roles, no accounts and nowhere to hold stock.
    expect($vendor->slug)->not->toBeEmpty()
        ->and($vendor->defaultStore)->not->toBeNull()
        ->and(Role::where('team_id', $vendor->id)->count())->toBeGreaterThan(0)
        ->and($vendor->route ?? route('filament.vendor.home', ['tenant' => $vendor->slug]))->toContain($vendor->slug);
});

// One approval, one vendor. An approved application then leaves the pending
// list entirely, so the table cannot act on it again; the re-check under
// lockForUpdate inside the action covers the concurrent case that the table
// cannot reach.
test('one approval makes exactly one vendor', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Once Only');

    Livewire::test(ListVendorApplications::class)
        ->callTableAction('approve', $application, data: ['admin_notes' => null]);

    expect(Vendor::where('name', 'Once Only')->count())->toBe(1)
        ->and($application->fresh()->status)->toBe('approved');
});

test('rejecting leaves no vendor behind', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Rejected Store');

    Livewire::test(ListVendorApplications::class)
        ->callTableAction('reject', $application, data: ['admin_notes' => 'Incomplete details']);

    expect($application->fresh()->status)->toBe('rejected')
        ->and(Vendor::where('name', 'Rejected Store')->exists())->toBeFalse();
});

// ── The View page's own approve action ────────────────────────────────────
// This one ran with no transaction: the vendor was committed on its own, so a
// later failure left it behind with the application still pending, and the next
// click made another. That is how one application ended up with two vendors.

test('approving from the application page creates exactly one vendor', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Chris Zion Gadget Store');

    Livewire::test(ViewVendorApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['admin_notes' => 'welcome']);

    expect(Vendor::where('name', 'Chris Zion Gadget Store')->count())->toBe(1)
        ->and($application->fresh()->status)->toBe('approved');
});

test('approving the same application twice from the page does not make a second vendor', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Double Click Store');

    // One page, clicked twice. A fresh mount would already see the application
    // as approved and hide the button; the case that actually bit was the page
    // still open on a record it believes is pending.
    $page = Livewire::test(ViewVendorApplication::class, ['record' => $application->id]);

    $page->callAction('approve', data: ['admin_notes' => null]);
    $page->callAction('approve', data: ['admin_notes' => null]);

    expect(Vendor::where('name', 'Double Click Store')->count())->toBe(1);
});

test('a failure part way through leaves no half-made vendor', function () {
    actingAsAdminForVendorApps();
    $application = pendingVendorApplication('Atomic Store');

    // Nothing should be committed unless the application itself is marked
    // approved — the two now live or die together.
    Livewire::test(ViewVendorApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['admin_notes' => null]);

    $vendor = Vendor::where('name', 'Atomic Store')->first();

    expect($vendor)->not->toBeNull()
        ->and($application->fresh()->status)->toBe('approved')
        ->and($vendor->users()->where('users.id', $application->user_id)->exists())->toBeTrue();
});
