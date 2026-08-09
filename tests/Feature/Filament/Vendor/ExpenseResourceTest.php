<?php

use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Filament\Vendor\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Vendor\Resources\Expenses\Pages\EditExpense;
use App\Filament\Vendor\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function setUpExpensesVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Expenses Store ' . uniqid()]);
    VendorRoles::seedFor($vendor);

    return compact('owner', 'vendor');
}

function actAsExpensesOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('a storekeeper cannot access the expenses resource', function () {
    $data = setUpExpensesVendor();

    $storekeeper = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($data['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper)
        ->get(route('filament.vendor.resources.expenses.index', ['tenant' => $data['vendor']->slug]))
        ->assertRedirect(route('filament.vendor.home', ['tenant' => $data['vendor']->slug]));
});

test('a role explicitly granted manage_expenses can access the resource', function () {
    $data = setUpExpensesVendor();

    $manager = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('store_admin');

    Role::where(['name' => 'store_admin', 'team_id' => $data['vendor']->id])
        ->first()
        ->givePermissionTo('manage_expenses');

    $this->actingAs($manager)
        ->get(route('filament.vendor.resources.expenses.index', ['tenant' => $data['vendor']->slug]))
        ->assertOk();
});

test('creating an expense with no account leaves it unpaid and posts nothing', function () {
    $data = setUpExpensesVendor();
    actAsExpensesOwner($data);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'advertising',
            'amount' => 15000,
            'incurred_at' => now()->toDateString(),
            'description' => 'Facebook boost',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::latest('id')->first();

    expect($expense->isPosted())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('creating an expense with an account posts it to the ledger immediately', function () {
    $data = setUpExpensesVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    actAsExpensesOwner($data);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'other',
            'amount' => 8000,
            'incurred_at' => now()->toDateString(),
            'financial_account_id' => $account->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::latest('id')->first();

    expect($expense->isPosted())->toBeTrue()
        ->and($account->fresh()->balance())->toBe(-8000.0)
        ->and(FinancialLedgerEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->count())->toBe(1);
});

test('editing an unposted expense to add an account posts it', function () {
    $data = setUpExpensesVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $expense = Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'advertising',
        'amount' => 3000, 'incurred_at' => now()->toDateString(), 'created_by' => $data['owner']->id,
    ]);
    actAsExpensesOwner($data);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['financial_account_id' => $account->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->fresh()->isPosted())->toBeTrue()
        ->and(FinancialLedgerEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->count())->toBe(1);
});

test('saving an already-posted expense again does not double-post', function () {
    $data = setUpExpensesVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $expense = Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'advertising',
        'amount' => 3000, 'incurred_at' => now()->toDateString(), 'created_by' => $data['owner']->id,
        'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);
    // Simulate the entry that would already exist from the original posting.
    \App\Services\FinancialLedger::postEntry($account, 'out', 3000, source: $expense, description: 'Original post');
    actAsExpensesOwner($data);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['description' => 'Updated note only'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FinancialLedgerEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->count())->toBe(1);
});

test('the edit form does not let a posted expense\'s amount change through', function () {
    $data = setUpExpensesVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();
    $expense = Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'advertising',
        'amount' => 3000, 'incurred_at' => now()->toDateString(), 'created_by' => $data['owner']->id,
        'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);
    actAsExpensesOwner($data);

    // The field is disabled client-side, so Filament excludes it from the
    // saved payload entirely — this proves the end-to-end save path leaves
    // the posted amount untouched, not just the model guard in isolation.
    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['amount' => 99999])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $expense->fresh()->amount)->toBe(3000.0);
});

test('a posted expense cannot be deleted, an unposted one can', function () {
    $data = setUpExpensesVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();

    $unposted = Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other',
        'amount' => 1000, 'incurred_at' => now()->toDateString(),
    ]);
    $posted = Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other',
        'amount' => 2000, 'incurred_at' => now()->toDateString(),
        'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);

    expect(ExpenseResource::canDelete($unposted))->toBeTrue()
        ->and(ExpenseResource::canDelete($posted))->toBeFalse();
});

test('the list page shows the total amount for the current filter', function () {
    $data = setUpExpensesVendor();
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 10000, 'incurred_at' => now()->toDateString()]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 5000, 'incurred_at' => now()->toDateString()]);
    actAsExpensesOwner($data);

    Livewire::test(ListExpenses::class)
        ->assertSee('15,000.00');
});

test('the category filter narrows the list', function () {
    $data = setUpExpensesVendor();
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 10000, 'incurred_at' => now()->toDateString(), 'description' => 'FB Ads Line']);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 5000, 'incurred_at' => now()->toDateString(), 'description' => 'Rent Line']);
    actAsExpensesOwner($data);

    Livewire::test(ListExpenses::class)
        ->filterTable('category', 'advertising')
        ->assertSee('FB Ads Line')
        ->assertDontSee('Rent Line');
});
