<?php

use App\Filament\Vendor\Pages\ReceiptSettings;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorReceiptSetting;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Saving receipt settings crashed with "Undefined array key loyalty_reward_text"
// whenever the loyalty card was off, because its fields are hidden then and the
// form does not send them.

function receiptSettingsAsOwner(): Vendor
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Receipt Store']);
    VendorRoles::seedFor($vendor);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return $vendor;
}

test('saves with the loyalty card off', function () {
    $vendor = receiptSettingsAsOwner();

    Livewire::test(ReceiptSettings::class)
        ->fillForm(['header_name' => 'Veruzo Superstore', 'loyalty_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(VendorReceiptSetting::where('vendor_id', $vendor->id)->value('header_name'))->toBe('Veruzo Superstore');
});

test('keeps the reward when the card is switched off and on again', function () {
    $vendor = receiptSettingsAsOwner();

    Livewire::test(ReceiptSettings::class)
        ->fillForm(['loyalty_enabled' => true, 'loyalty_goal' => 8, 'loyalty_reward_text' => 'A free drink'])
        ->call('save');

    Livewire::test(ReceiptSettings::class)
        ->fillForm(['loyalty_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = VendorReceiptSetting::where('vendor_id', $vendor->id)->firstOrFail();

    expect($settings->loyalty_enabled)->toBeFalsy()
        ->and($settings->loyalty_goal)->toBe(8)
        ->and($settings->loyalty_reward_text)->toBe('A free drink');
});
