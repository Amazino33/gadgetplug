<?php

use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// The admin end of the block. Rendering these two pages is most of the point:
// a Filament schema that misuses an API only fails when something draws it.

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($this->admin);

    $this->vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Admin Block Store',
    ]);
});

it('blocks a vendor from the list action, with the reason it was given', function () {
    Livewire::test(ListVendors::class)
        ->callTableAction('toggleDashboardBlock', $this->vendor, [
            'dashboard_blocked_reason' => 'Unpaid commission for August.',
        ])
        ->assertHasNoTableActionErrors();

    $this->vendor->refresh();

    expect($this->vendor->dashboard_blocked)->toBeTrue()
        ->and($this->vendor->dashboard_blocked_reason)->toBe('Unpaid commission for August.')
        ->and($this->vendor->dashboard_blocked_at)->not->toBeNull();
});

it('will not block without a reason, since the vendor is shown it', function () {
    Livewire::test(ListVendors::class)
        ->callTableAction('toggleDashboardBlock', $this->vendor, [
            'dashboard_blocked_reason' => null,
        ])
        ->assertHasTableActionErrors(['dashboard_blocked_reason']);

    expect($this->vendor->refresh()->dashboard_blocked)->toBeFalse();
});

it('restores access from the list action and forgets the old reason', function () {
    $this->vendor->update([
        'dashboard_blocked'        => true,
        'dashboard_blocked_reason' => 'Unpaid commission for August.',
    ]);

    Livewire::test(ListVendors::class)
        ->callTableAction('toggleDashboardBlock', $this->vendor)
        ->assertHasNoTableActionErrors();

    $this->vendor->refresh();

    expect($this->vendor->dashboard_blocked)->toBeFalse()
        ->and($this->vendor->dashboard_blocked_reason)->toBeNull()
        ->and($this->vendor->dashboard_blocked_at)->toBeNull();
});

it('blocks from the edit form too', function () {
    // Vendor::getRouteKeyName() is the slug, so that is what the page resolves.
    Livewire::test(EditVendor::class, ['record' => $this->vendor->slug])
        ->assertFormFieldExists('dashboard_blocked')
        ->fillForm([
            'dashboard_blocked'        => true,
            'dashboard_blocked_reason' => 'Terms breach: reselling outside the agreement.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->vendor->refresh()->dashboard_blocked)->toBeTrue();
});

it('records who blocked the vendor and why in the activity log', function () {
    $this->vendor->update([
        'dashboard_blocked'        => true,
        'dashboard_blocked_reason' => 'Unpaid commission for August.',
    ]);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('description', 'like', 'Dashboard access blocked%')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['reason'])->toBe('Unpaid commission for August.');
});
