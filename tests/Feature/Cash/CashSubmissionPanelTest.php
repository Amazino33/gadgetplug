<?php

use App\Filament\Vendor\Resources\CashSubmissions\CashSubmissionResource;
use App\Filament\Vendor\Resources\CashSubmissions\Pages\ListCashSubmissions;
use App\Filament\Vendor\Resources\Roles\RoleResource;
use App\Models\CashSubmission;
use App\Models\User;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

function cashPanel(App\Models\Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

test('the screen shows what you are still holding', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $oga = User::find($vendor->user_id);

    cashSale($vendor, $store, $oga->id, ['total' => 75000]);
    cashPanel($vendor, $oga);

    Livewire::test(ListCashSubmissions::class)
        ->assertOk()
        ->assertSee('75,000.00');
});

test('a handover is listed with both names and the difference', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $oga = User::find($vendor->user_id);
    $chioma = User::factory()->create(['name' => 'Chioma']);

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);
    app(App\Actions\Cash\SubmitCashAction::class)
        ->execute($chioma, $oga, $store, 47000, 'Bought diesel');

    cashPanel($vendor, $oga);

    Livewire::test(ListCashSubmissions::class)
        ->assertOk()
        ->assertSee('Chioma')
        ->assertSee('Bought diesel');
});

test('only the receiver is offered the confirm action', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $oga = User::find($vendor->user_id);
    $chioma = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$chioma->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);
    $submission = app(App\Actions\Cash\SubmitCashAction::class)->execute($chioma, $oga, $store, 50000);

    cashPanel($vendor, $oga);
    Livewire::test(ListCashSubmissions::class)
        ->assertTableActionVisible('confirm', $submission);

    // The person who handed it over cannot sign it off, even though they can
    // see it — that is the whole control.
    cashPanel($vendor, $chioma);
    Livewire::test(ListCashSubmissions::class)
        ->assertTableActionHidden('confirm', $submission);
});

test('a storekeeper sees only handovers they are part of', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $oga = User::find($vendor->user_id);

    $chioma = User::factory()->create();
    $ibrahim = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$chioma->id, $ibrahim->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');

    cashSale($vendor, $store, $chioma->id, ['total' => 20000]);
    cashSale($vendor, $store, $ibrahim->id, ['total' => 90000]);

    $mine = app(App\Actions\Cash\SubmitCashAction::class)->execute($chioma, $oga, $store, 20000);
    $theirs = app(App\Actions\Cash\SubmitCashAction::class)->execute($ibrahim, $oga, $store, 90000);

    cashPanel($vendor, $chioma);

    Livewire::test(ListCashSubmissions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

test('a storekeeper gets submit_cash but not receive_cash', function () {
    $vendor = cashVendor();
    $vendor->users()->syncWithoutDetaching([$chioma = User::factory()->create()->id]);
    cashRoles($vendor);

    $user = User::find($chioma);
    $user->assignRole('storekeeper');

    expect($user->hasVendorPermission($vendor->id, 'submit_cash'))->toBeTrue()
        // Receiving is somebody else's job by design: two names, two people.
        ->and($user->hasVendorPermission($vendor->id, 'receive_cash'))->toBeFalse();
});

test('both cash permissions can be granted from the roles screen', function () {
    expect(RoleResource::GRANTABLE_PERMISSIONS)
        ->toContain('submit_cash')
        ->toContain('receive_cash');
});
