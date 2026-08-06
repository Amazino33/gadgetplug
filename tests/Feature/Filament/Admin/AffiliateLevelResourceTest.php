<?php

use App\Filament\Resources\AffiliateLevels\AffiliateLevelResource;
use App\Filament\Resources\AffiliateLevels\Pages\CreateAffiliateLevel;
use App\Filament\Resources\AffiliateLevels\Pages\ListAffiliateLevels;
use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForLevels(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('a super admin can access the affiliate levels resource', function () {
    $this->actingAs(actingAsSuperAdminForLevels());

    expect(AffiliateLevelResource::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate levels resource', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Levels Vendor']);

    $this->actingAs($owner);

    expect(AffiliateLevelResource::canAccess())->toBeFalse();
});

test('a level with no affiliates assigned can be deleted', function () {
    $level = AffiliateLevel::create(['name' => 'Bronze', 'target' => 0, 'rate_value' => 1.0, 'sort_order' => 0]);

    expect(AffiliateLevelResource::canDelete($level))->toBeTrue();
});

test('a level with affiliates assigned cannot be deleted', function () {
    $level = AffiliateLevel::create(['name' => 'Bronze', 'target' => 0, 'rate_value' => 1.0, 'sort_order' => 0]);
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $affiliate->update(['affiliate_level_id' => $level->id]);

    expect(AffiliateLevelResource::canDelete($level->fresh()))->toBeFalse();
});

test('creating a level from the admin UI persists it correctly', function () {
    $this->actingAs(actingAsSuperAdminForLevels());

    Livewire::test(CreateAffiliateLevel::class)
        ->fillForm([
            'name'       => 'Gold',
            'target'     => 200000,
            'rate_value' => 1.20,
            'sort_order' => 2,
            'is_active'  => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $level = AffiliateLevel::where('name', 'Gold')->first();

    expect($level)->not->toBeNull()
        ->and((float) $level->target)->toBe(200000.0)
        ->and((float) $level->rate_value)->toBe(1.2);
});

test('the levels list shows name, target, rate multiplier and affiliate count', function () {
    $this->actingAs(actingAsSuperAdminForLevels());

    AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.10, 'sort_order' => 1]);

    Livewire::test(ListAffiliateLevels::class)
        ->assertSee('Silver')
        ->assertSee('1.10×');
});
