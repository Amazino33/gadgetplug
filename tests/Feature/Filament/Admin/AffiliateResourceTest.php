<?php

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Models\Affiliate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForAffiliates(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('a super admin can access the affiliate resource', function () {
    $this->actingAs(actingAsSuperAdminForAffiliates());

    expect(AffiliateResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Affiliate Vendor']);

    $this->actingAs($owner);

    expect(AffiliateResource::canAccess())->toBeFalse();
});

test('the affiliate resource cannot be deleted from', function () {
    expect(AffiliateResource::canDelete(new Affiliate()))->toBeFalse();
});

test('creating an affiliate from an existing user auto-generates a unique code', function () {
    $this->actingAs(actingAsSuperAdminForAffiliates());

    $candidate = User::factory()->create(['name' => 'Future Affiliate']);

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'user_id'   => $candidate->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $affiliate = Affiliate::where('user_id', $candidate->id)->first();

    expect($affiliate)->not->toBeNull()
        ->and($affiliate->code)->not->toBeEmpty();
});

test('a user who already has an affiliate profile is not offered again as a candidate', function () {
    $this->actingAs(actingAsSuperAdminForAffiliates());

    $existingAffiliateUser = User::factory()->create(['name' => 'Already An Affiliate']);
    Affiliate::findOrCreateForUser($existingAffiliateUser);

    Livewire::test(CreateAffiliate::class)
        ->assertDontSee('Already An Affiliate');
});

test('the affiliate list shows code, name and balances', function () {
    $this->actingAs(actingAsSuperAdminForAffiliates());

    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create(['name' => 'List Test Affiliate']));

    Livewire::test(ListAffiliates::class)
        ->assertSee($affiliate->code)
        ->assertSee('List Test Affiliate');
});
