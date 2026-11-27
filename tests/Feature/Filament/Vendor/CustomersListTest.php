<?php

use App\Filament\Vendor\Resources\Customers\Pages\ManageCustomers;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The panel's customer list — every customer, not only those who owe.

function customersListVendor(): Vendor
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Veruzo Superstore']);
    VendorRoles::seedFor($vendor);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return $vendor;
}

test('lists every customer of this shop, with what each owes', function () {
    $vendor = customersListVendor();

    $owes = PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Mama Nkechi', 'phone' => '08031234567']);
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $owes->id, 'vendor_id' => $vendor->id, 'direction' => 'charge',
        'amount' => 15000, 'occurred_at' => now()->toDateString(), 'description' => 'Opening balance — import',
    ]);
    $clear = PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Emeka Stores']);
    $theirs = PosCustomer::create(['vendor_id' => Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other'])->id, 'name' => 'Not Mine']);

    Livewire::test(ManageCustomers::class)
        ->assertCanSeeTableRecords([$owes, $clear])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee('₦15,000.00')
        ->filterTable('owes')
        ->assertCanSeeTableRecords([$owes])
        ->assertCanNotSeeTableRecords([$clear]);
});

test('adds a customer, and refuses a phone number another customer already has', function () {
    $vendor = customersListVendor();
    PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Existing', 'phone' => '08031234567']);

    Livewire::test(ManageCustomers::class)
        ->callAction('create', ['name' => 'Ada', 'phone' => '08059876543'])
        ->assertHasNoActionErrors();

    expect(PosCustomer::where('name', 'Ada')->value('vendor_id'))->toBe($vendor->id);

    Livewire::test(ManageCustomers::class)
        ->callAction('create', ['name' => 'Copy', 'phone' => '08031234567'])
        ->assertHasActionErrors(['phone']);
});

test('is closed to staff with no business seeing customer details', function () {
    $vendor = customersListVendor();

    $member = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$member->id]);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('member');

    $this->actingAs($member);
    Filament::setTenant($vendor);

    expect(\App\Filament\Vendor\Resources\Customers\CustomerResource::canAccess())->toBeFalse();
});
