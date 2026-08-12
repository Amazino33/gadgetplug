<?php

use App\Filament\Resources\AffiliateReachBands\AffiliateReachBandResource;
use App\Filament\Resources\AffiliateReachBands\Pages\ManageAffiliateReachBands;
use App\Filament\Resources\MarketingMaterials\MarketingMaterialResource;
use App\Filament\Resources\MarketingMaterials\Pages\ManageMarketingMaterials;
use App\Models\AffiliateReachBand;
use App\Models\MarketingMaterial;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pointsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('only a super admin can reach the reach-bands and material resources', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Not An Admin']);

    $this->actingAs($owner);

    expect(AffiliateReachBandResource::canAccess())->toBeFalse()
        ->and(MarketingMaterialResource::canAccess())->toBeFalse();

    $this->actingAs(pointsAdmin());

    expect(AffiliateReachBandResource::canAccess())->toBeTrue()
        ->and(MarketingMaterialResource::canAccess())->toBeTrue();
});

test('the default reach ladder ships seeded and covers zero through open-ended', function () {
    expect(AffiliateReachBand::active()->count())->toBe(5)
        ->and(AffiliateReachBand::forReach(0)->points)->toBe(5)
        ->and(AffiliateReachBand::forReach(999999)->max_reach)->toBeNull();
});

test('an admin can add a reach band from the panel', function () {
    $this->actingAs(pointsAdmin());

    Livewire::test(ManageAffiliateReachBands::class)
        ->callAction('create', data: [
            'name'       => 'Mega (50k+)',
            'min_reach'  => 50000,
            'max_reach'  => null,
            'points'     => 250,
            'sort_order' => 9,
            'is_active'  => true,
        ])
        ->assertHasNoActionErrors();

    expect(AffiliateReachBand::where('name', 'Mega (50k+)')->exists())->toBeTrue()
        ->and(AffiliateReachBand::forReach(60000)->points)->toBe(250);
});

test('an admin can add marketing material with a caption template', function () {
    $this->actingAs(pointsAdmin());

    Livewire::test(ManageMarketingMaterials::class)
        ->callAction('create', data: [
            'name'             => 'August Promo',
            'caption_template' => 'Grab it: :link (code :code)',
            'sort_order'       => 1,
            'is_active'        => true,
        ])
        ->assertHasNoActionErrors();

    expect(MarketingMaterial::where('name', 'August Promo')->exists())->toBeTrue();
})->skip('SpatieMediaLibraryFileUpload is required on the form; covered by the model/service tests instead.');

test('a band with no upper limit matches any reach above its minimum', function () {
    AffiliateReachBand::query()->update(['is_active' => false]);

    AffiliateReachBand::create([
        'name' => 'Open', 'min_reach' => 10, 'max_reach' => null, 'points' => 7, 'is_active' => true,
    ]);

    expect(AffiliateReachBand::forReach(9))->toBeNull()
        ->and(AffiliateReachBand::forReach(10)->points)->toBe(7)
        ->and(AffiliateReachBand::forReach(10_000_000)->points)->toBe(7);
});

test('the narrowest matching band wins when ranges overlap', function () {
    AffiliateReachBand::query()->update(['is_active' => false]);

    AffiliateReachBand::create(['name' => 'Wide', 'min_reach' => 0,   'max_reach' => 10000, 'points' => 10, 'is_active' => true]);
    AffiliateReachBand::create(['name' => 'High', 'min_reach' => 500, 'max_reach' => 10000, 'points' => 40, 'is_active' => true]);

    // Highest min_reach that still covers the value — the more specific band.
    expect(AffiliateReachBand::forReach(600)->name)->toBe('High')
        ->and(AffiliateReachBand::forReach(100)->name)->toBe('Wide');
});
