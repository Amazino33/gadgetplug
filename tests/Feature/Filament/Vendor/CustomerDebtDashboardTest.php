<?php

use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Filament\Vendor\Widgets\CustomerDebtOverview;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A vendor with two branches and roles seeded, ready to hang debts on. */
function debtDashboardContext(): array
{
    $ctx = repaymentContext(0);
    (new VendorPermissionsSeeder())->run();
    VendorRoles::seedFor($ctx['vendor']);

    $ctx['branch'] = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Itel Home']);

    return $ctx;
}

function debtStaff(array $ctx, string $role): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$user->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $user->assignRole($role);

    // Assigned to both branches, as a real member of staff is: ActiveStore only
    // hands a non-owner a store they actually work in, so without this the
    // store selector resolves to nothing and the scoping cannot be exercised.
    $user->stores()->syncWithoutDetaching([$ctx['store']->id, $ctx['branch']->id]);

    return $user;
}

function creditGivenBy(array $ctx, User $staff, ?Store $store, float $amount, ?PosCustomer $customer = null): PosCustomer
{
    $customer ??= PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id,
        'name'      => 'Customer ' . uniqid(),
        'phone'     => '080' . random_int(10000000, 99999999),
    ]);

    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $customer->id,
        'vendor_id'       => $ctx['vendor']->id,
        'direction'       => 'charge',
        'amount'          => $amount,
        'store_id'        => $store?->id,
        'created_by'      => $staff->id,
        'occurred_at'     => now()->toDateString(),
    ]);

    return $customer;
}

function actAsDebtStaff(array $ctx, User $user, ?Store $store = null): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    if ($store) {
        ActiveStore::set($ctx['vendor'], $user, $store);
    }
}

// ─── A storekeeper can now reach the debts ──────────────────────────────

it('lets a storekeeper reach the debt list so they can take a payment', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');

    actAsDebtStaff($ctx, $storekeeper);

    expect(CustomerDebtResource::canAccess())->toBeTrue();
});

it('lets a storekeeper record a repayment', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');
    $customer    = creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);

    app(RecordCustomerPaymentAction::class)->execute(
        customer: $customer, amount: 2000, collectedBy: $storekeeper, storeId: $ctx['store']->id,
    );

    expect(app(App\Services\Pos\CustomerDebtService::class)->outstanding($customer->id))->toBe(3000.0);
});

it('still refuses a storekeeper the write-off', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');
    $customer    = creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);

    // Collecting is everyone's job; forgiving is the owner's alone.
    expect(app(App\Policies\PosCustomerDebtPolicy::class)->writeOff($storekeeper, $customer))
        ->toBeFalse();
});

// ─── Store scoping of the list ──────────────────────────────────────────

it('shows a storekeeper only the customers who took credit at their branch', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');

    $here      = creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);
    $elsewhere = creditGivenBy($ctx, $storekeeper, $ctx['branch'], 8000);

    actAsDebtStaff($ctx, $storekeeper, $ctx['store']);

    expect(CustomerDebtResource::getEloquentQuery()->pluck('id')->all())->toBe([$here->id]);

    actAsDebtStaff($ctx, $storekeeper, $ctx['branch']);

    expect(CustomerDebtResource::getEloquentQuery()->pluck('id')->all())->toBe([$elsewhere->id]);
});

it('shows the owner every branch regardless of the store selector', function () {
    $ctx = debtDashboardContext();
    $cashier = debtStaff($ctx, 'storekeeper');

    creditGivenBy($ctx, $cashier, $ctx['store'], 5000);
    creditGivenBy($ctx, $cashier, $ctx['branch'], 8000);

    actAsDebtStaff($ctx, $ctx['owner'], $ctx['store']);

    expect(CustomerDebtResource::getEloquentQuery()->count())->toBe(2);
});

it('drops a customer off every branch list once they have paid in full', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');
    $customer    = creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);

    // Paid at the OTHER branch — they must not still be chased at the first.
    app(RecordCustomerPaymentAction::class)->execute(
        customer: $customer, amount: 5000, collectedBy: $storekeeper, storeId: $ctx['branch']->id,
    );

    actAsDebtStaff($ctx, $storekeeper, $ctx['store']);

    expect(CustomerDebtResource::getEloquentQuery()->count())->toBe(0);
});

// ─── The dashboard card ─────────────────────────────────────────────────

it('shows the vendor-wide total to a storekeeper', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');

    creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);
    creditGivenBy($ctx, $storekeeper, $ctx['branch'], 8000);

    actAsDebtStaff($ctx, $storekeeper, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)
        ->assertSee('Still owed')
        ->assertSee('13,000.00');
});

it('shows the branch figure beside the vendor total', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');

    creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);
    creditGivenBy($ctx, $storekeeper, $ctx['branch'], 8000);

    actAsDebtStaff($ctx, $storekeeper, $ctx['branch']);

    Livewire::test(CustomerDebtOverview::class)
        ->assertSee('Itel Home')
        ->assertSee('8,000.00')     // this branch
        ->assertSee('13,000.00');   // all stores
});

it('shows each storekeeper only the credit they themselves gave', function () {
    $ctx    = debtDashboardContext();
    $ada    = debtStaff($ctx, 'storekeeper');
    $bola   = debtStaff($ctx, 'storekeeper');

    creditGivenBy($ctx, $ada, $ctx['store'], 5000);
    creditGivenBy($ctx, $bola, $ctx['store'], 9000);

    actAsDebtStaff($ctx, $ada, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)
        ->assertSee('Credit you gave')
        ->assertSee('5,000.00');
});

it('reduces what a storekeeper gave as it is repaid, whoever collects it', function () {
    $ctx      = debtDashboardContext();
    $ada      = debtStaff($ctx, 'storekeeper');
    $bola     = debtStaff($ctx, 'storekeeper');
    $customer = creditGivenBy($ctx, $ada, $ctx['store'], 5000);

    // Bola takes the money; Ada's exposure still falls, because the debt is
    // part-recovered whoever was at the counter.
    app(RecordCustomerPaymentAction::class)->execute(
        customer: $customer, amount: 2000, collectedBy: $bola, storeId: $ctx['store']->id,
    );

    actAsDebtStaff($ctx, $ada, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)->assertSee('3,000.00');
});

it('never lets one storekeeper collection push another figure negative', function () {
    $ctx      = debtDashboardContext();
    $ada      = debtStaff($ctx, 'storekeeper');
    $customer = creditGivenBy($ctx, $ada, $ctx['store'], 5000);

    // Overpaid — the customer is now in credit overall.
    app(RecordCustomerPaymentAction::class)->execute(
        customer: $customer, amount: 6000, collectedBy: $ada, storeId: $ctx['store']->id,
    );

    actAsDebtStaff($ctx, $ada, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)
        ->assertSee('Nothing outstanding from your sales');
});

it('links every figure through to the debt list', function () {
    $ctx         = debtDashboardContext();
    $storekeeper = debtStaff($ctx, 'storekeeper');
    creditGivenBy($ctx, $storekeeper, $ctx['store'], 5000);

    actAsDebtStaff($ctx, $storekeeper, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)
        ->assertSee(CustomerDebtResource::getUrl('index'));
});

it('shows nothing at all to someone who cannot see debts', function () {
    $ctx    = debtDashboardContext();
    $member = debtStaff($ctx, 'member');

    actAsDebtStaff($ctx, $member, $ctx['store']);

    Livewire::test(CustomerDebtOverview::class)->assertDontSee('Still owed');
});
