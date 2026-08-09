<?php

use App\Filament\Vendor\Resources\FinancialAccounts\FinancialAccountResource;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinancialLedger;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function setUpFinancialAccountsVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Financial Accounts Store ' . uniqid()]);
    VendorRoles::seedFor($vendor);

    return compact('owner', 'vendor');
}

function actAsFinancialAccountsOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('a storekeeper cannot access the financial accounts resource', function () {
    $data = setUpFinancialAccountsVendor();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.resources.financial-accounts.index', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

test('a role explicitly granted manage_financial_accounts can access the resource', function () {
    $data = setUpFinancialAccountsVendor();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('manage_financial_accounts');

    $this->actingAs($manager)
        ->get(route('filament.vendor.resources.financial-accounts.index', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

test('the vendor owner can always access the resource', function () {
    $data = setUpFinancialAccountsVendor();
    actAsFinancialAccountsOwner($data);

    Livewire::test(\App\Filament\Vendor\Resources\FinancialAccounts\Pages\ListFinancialAccounts::class)
        ->assertSuccessful();
});

test('the list page shows both accounts with their live derived balance', function () {
    $data = setUpFinancialAccountsVendor();
    actAsFinancialAccountsOwner($data);

    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    $bank->update(['opening_balance' => 10000]);
    FinancialLedger::postEntry($bank, 'in', 5000, description: 'Test sale');

    Livewire::test(\App\Filament\Vendor\Resources\FinancialAccounts\Pages\ListFinancialAccounts::class)
        ->assertSee('Bank Account')
        ->assertSee('Cash Account')
        ->assertSee('15,000.00');
});

test('the owner can update the opening balance', function () {
    $data = setUpFinancialAccountsVendor();
    actAsFinancialAccountsOwner($data);

    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();

    Livewire::test(\App\Filament\Vendor\Resources\FinancialAccounts\Pages\EditFinancialAccount::class, ['record' => $cash->getRouteKey()])
        ->fillForm(['opening_balance' => 25000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $cash->fresh()->opening_balance)->toBe(25000.0);
});

test('accounts cannot be created or deleted through this resource', function () {
    expect(FinancialAccountResource::canCreate())->toBeFalse();

    $data = setUpFinancialAccountsVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();

    expect(FinancialAccountResource::canDelete($account))->toBeFalse();
});

test('the ledger tab shows entries for that account, newest first', function () {
    $data = setUpFinancialAccountsVendor();
    actAsFinancialAccountsOwner($data);

    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    FinancialLedger::postEntry($bank, 'in', 1000, description: 'Older entry', occurredAt: now()->subDays(2));
    FinancialLedger::postEntry($bank, 'out', 300, description: 'Newer entry', occurredAt: now());

    Livewire::test(\App\Filament\Vendor\Resources\FinancialAccounts\RelationManagers\LedgerEntriesRelationManager::class, [
        'ownerRecord' => $bank,
        'pageClass'   => \App\Filament\Vendor\Resources\FinancialAccounts\Pages\EditFinancialAccount::class,
    ])
        ->assertSee('Older entry')
        ->assertSee('Newer entry');
});
