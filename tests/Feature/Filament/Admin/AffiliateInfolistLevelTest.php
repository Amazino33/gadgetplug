<?php

use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function actingAsSuperAdminForInfolist(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('an affiliate with no level shows a placeholder instead of a level badge', function () {
    $this->actingAs(actingAsSuperAdminForInfolist());

    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSee('No level yet');
});

test('an affiliate with a level shows the level name and progress to the next tier', function () {
    $this->actingAs(actingAsSuperAdminForInfolist());

    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0, 'rate_value' => 1.0, 'sort_order' => 0]);
    $silver = AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1]);

    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $affiliate->update(['affiliate_level_id' => $silver->id, 'level_achieved_at' => now()]);

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSee('Silver');
});

test('an affiliate close to the inactivity window shows a warning-level demotion risk badge', function () {
    $this->actingAs(actingAsSuperAdminForInfolist());

    AffiliateSetting::current()->update(['inactivity_demotion_days' => 21]);
    $level = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 1]);

    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $affiliate->update(['affiliate_level_id' => $level->id, 'level_achieved_at' => now()->subDays(30)]);
    $affiliate->timestamps = false;
    $affiliate->created_at = now()->subDays(18); // 3 days of inactivity budget left
    $affiliate->save();

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSee('day(s) of inactivity left before demotion');
});
