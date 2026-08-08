<?php

use App\Filament\Resources\AffiliateApplications\AffiliateApplicationResource;
use App\Filament\Resources\AffiliateApplications\Pages\ListAffiliateApplications;
use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForApplications(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

function makePendingApplication(): AffiliateApplication
{
    return AffiliateApplication::create([
        'user_id'  => User::factory()->create()->id,
        'whatsapp' => '+2348000000000',
        'reason'   => 'I promote GadgetPlug through my social media pages and community groups.',
        'status'   => 'pending',
    ]);
}

test('a super admin can access the affiliate applications resource', function () {
    $this->actingAs(actingAsSuperAdminForApplications());

    expect(AffiliateApplicationResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate applications resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Application Vendor']);
    $this->actingAs($owner);

    expect(AffiliateApplicationResource::canAccess())->toBeFalse();
});

test('approving an application creates a real affiliate record and marks it approved', function () {
    $admin = actingAsSuperAdminForApplications();
    $this->actingAs($admin);

    $application = makePendingApplication();

    Livewire::test(ListAffiliateApplications::class)
        ->callTableAction('approve', $application, data: ['admin_notes' => 'Welcome!']);

    expect($application->fresh()->status)->toBe('approved')
        ->and(Affiliate::where('user_id', $application->user_id)->exists())->toBeTrue();
});

test('the approve button disappears once an application is approved, preventing a second click', function () {
    $this->actingAs(actingAsSuperAdminForApplications());

    $application = makePendingApplication();

    // Filament's own test harness refuses to invoke an action it considers
    // hidden — a genuine double-click race under true concurrency isn't
    // reproducible through this synchronous, single-threaded API, but this
    // proves the first line of defense: the button is gone the moment the
    // record is no longer 'pending', so the UI itself can't fire a second
    // request. The DB::transaction + lockForUpdate + status re-check inside
    // the action itself (same pattern already proven for exactly this
    // scenario in AffiliateTaskServiceTest's direct-service double-approve
    // test) is the second line of defense, for the narrower race a real
    // double-click before the button disappears could still cause.
    $component = Livewire::test(ListAffiliateApplications::class)
        ->set('activeTab', 'all');

    $component->callTableAction('approve', $application, data: ['admin_notes' => 'First click']);

    expect(Affiliate::where('user_id', $application->user_id)->count())->toBe(1);

    $component->assertTableActionHidden('approve', $application->fresh());
});

test('rejecting an application requires a reason and creates no affiliate', function () {
    $this->actingAs(actingAsSuperAdminForApplications());

    $application = makePendingApplication();

    Livewire::test(ListAffiliateApplications::class)
        ->callTableAction('reject', $application, data: ['admin_notes' => 'Audience too small right now.']);

    expect($application->fresh()->status)->toBe('rejected')
        ->and($application->fresh()->admin_notes)->toBe('Audience too small right now.')
        ->and(Affiliate::where('user_id', $application->user_id)->exists())->toBeFalse();
});

test('the pending tab shows the correct count badge', function () {
    $this->actingAs(actingAsSuperAdminForApplications());

    makePendingApplication();
    makePendingApplication();

    expect(AffiliateApplicationResource::getNavigationBadge())->toBe('2');
});
