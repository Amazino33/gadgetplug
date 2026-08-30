<?php

use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Filament\Vendor\Resources\CustomerDebts\Pages\ListCustomerDebts;
use App\Filament\Vendor\Resources\CustomerDebts\Pages\ViewCustomerDebt;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\Pos\CustomerDebtService;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Facades\Filament;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── The two movements ──────────────────────────────────────────────────

it('reduces what the customer owes', function () {
    $ctx = repaymentContext();

    app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);
});

it('puts the money into the cash account, stamped with staff and store', function () {
    $ctx = repaymentContext();

    $payment = app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    $entry = FinancialLedgerEntry::where('source_id', $payment->id)
        ->where('source_type', $payment->getMorphClass())
        ->firstOrFail();

    expect((float) $entry->amount)->toBe(4000.0)
        ->and($entry->direction)->toBe('in')
        ->and($entry->created_by)->toBe($ctx['owner']->id)
        ->and($entry->store_id)->toBe($ctx['store']->id);
});

it('stamps the ledger row with the collecting staff and store too', function () {
    $ctx = repaymentContext();

    $payment = app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    expect($payment->created_by)->toBe($ctx['owner']->id)
        ->and($payment->store_id)->toBe($ctx['store']->id)
        ->and((float) $payment->amount)->toBe(-4000.0);
});

// ─── The idempotency trap this design exists to avoid ───────────────────

it('posts BOTH of two repayments — the second is not swallowed', function () {
    $ctx = repaymentContext();
    $action = app(RecordCustomerPaymentAction::class);

    // Same customer, same amount, same day. Sourced from a shared row this
    // would hit FinancialLedger's (source, direction) guard and silently vanish.
    $action->execute(customer: $ctx['customer'], amount: 2500, collectedBy: $ctx['owner'], storeId: $ctx['store']->id);
    $action->execute(customer: $ctx['customer'], amount: 2500, collectedBy: $ctx['owner'], storeId: $ctx['store']->id);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(5000.0)
        ->and(cashIn($ctx['vendor']->id))->toBe(5000.0)
        ->and(FinancialLedgerEntry::where('direction', 'in')->count())->toBe(2)
        ->and(PosCustomerLedgerEntry::where('direction', 'payment')->count())->toBe(2);
});

it('clears the balance when the last instalment lands', function () {
    $ctx = repaymentContext();
    $action = app(RecordCustomerPaymentAction::class);

    foreach ([3000, 3000, 4000] as $amount) {
        $action->execute(customer: $ctx['customer'], amount: $amount, collectedBy: $ctx['owner'], storeId: $ctx['store']->id);
    }

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($ctx['customer']->id))->toBe(0.0)
        ->and($debt->owesAnything($ctx['customer']->id))->toBeFalse()
        ->and(cashIn($ctx['vendor']->id))->toBe(10000.0);
});

// ─── Atomicity ──────────────────────────────────────────────────────────

it('records nothing at all when there is no cash account to receive it', function () {
    $ctx = repaymentContext();

    // The cash side has nowhere to go.
    FinancialAccount::where('vendor_id', $ctx['vendor']->id)->delete();

    expect(fn () => app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    ))->toThrow(RuntimeException::class);

    // Both halves rolled back: the debt is untouched and no payment row exists.
    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0)
        ->and(PosCustomerLedgerEntry::where('direction', 'payment')->count())->toBe(0);
});

it('refuses a zero or negative repayment', function () {
    $ctx = repaymentContext();

    expect(fn () => app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 0, collectedBy: $ctx['owner'],
    ))->toThrow(RuntimeException::class);

    expect(PosCustomerLedgerEntry::where('direction', 'payment')->count())->toBe(0);
});

// ─── Panel ──────────────────────────────────────────────────────────────

it('lists only customers who still owe', function () {
    $ctx = repaymentContext();

    $settled = PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'All Square', 'phone' => '08090000000',
    ]);
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $settled->id, 'vendor_id' => $ctx['vendor']->id,
        'direction' => 'charge', 'amount' => 500, 'occurred_at' => '2026-08-01',
    ]);
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $settled->id, 'vendor_id' => $ctx['vendor']->id,
        'direction' => 'payment', 'amount' => -500, 'occurred_at' => '2026-08-02',
    ]);

    actAsDebtOwner($ctx);

    $ids = CustomerDebtResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ctx['customer']->id]);
});

it('shows the derived figures on the list', function () {
    $ctx = repaymentContext();
    app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 2500, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    actAsDebtOwner($ctx);

    $row = CustomerDebtResource::getEloquentQuery()->firstOrFail();

    expect((float) $row->charged_amount)->toBe(10000.0)
        ->and((float) $row->outstanding_amount)->toBe(7500.0);
});

it('records a payment through the panel action, stamping the signed-in user', function () {
    $ctx = repaymentContext();

    actAsDebtOwner($ctx);

    Livewire::test(ListCustomerDebts::class)
        ->callTableAction('recordPayment', $ctx['customer'], data: ['amount' => 4000, 'note' => 'Part payment'])
        ->assertHasNoTableActionErrors();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);

    $payment = PosCustomerLedgerEntry::where('direction', 'payment')->firstOrFail();

    // Never typed — taken from the authenticated user.
    expect($payment->created_by)->toBe($ctx['owner']->id)
        ->and($payment->description)->toBe('Part payment');
});

it('renders the history with a running balance', function () {
    $ctx = repaymentContext();
    app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    actAsDebtOwner($ctx);

    Livewire::test(ViewCustomerDebt::class, ['record' => $ctx['customer']->id])
        ->assertSuccessful()
        ->assertSee('Credit sale')
        ->assertSee('Payment')
        // Charge 10,000 then payment 4,000 leaves 6,000 showing as the balance.
        ->assertSee('6,000.00');
});

it('never shows another vendor customer', function () {
    $mine   = repaymentContext();
    $theirs = repaymentContext();

    actAsDebtOwner($mine);

    // The page 404s rather than rendering someone else's balance. Livewire
    // turns the missing model into a response rather than letting the exception
    // escape, so the status is what there is to assert.
    $this->get(route('filament.vendor.resources.customer-debts.view', [
        'tenant' => $mine['vendor']->slug,
        'record' => $theirs['customer']->id,
    ]))->assertNotFound();

    // And the query behind it never returns them, which is the actual guard.
    expect(CustomerDebtResource::getEloquentQuery()->whereKey($theirs['customer']->id)->exists())
        ->toBeFalse();
});

// ─── Access ─────────────────────────────────────────────────────────────

it('lets a store admin and a storekeeper in, and keeps a plain member out', function () {
    $ctx = repaymentContext();
    (new VendorPermissionsSeeder())->run();
    VendorRoles::seedFor($ctx['vendor']);

    $admin = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$admin->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $admin->assignRole('store_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    expect(CustomerDebtResource::canAccess())->toBeTrue();

    // A storekeeper collects too — whoever is at the counter has to be able to
    // take a repayment. Their list is scoped to their branch, and write-off
    // stays refused; see CustomerDebtDashboardTest.
    $storekeeper = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $storekeeper->assignRole('storekeeper');

    $this->actingAs($storekeeper);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    expect(CustomerDebtResource::canAccess())->toBeTrue();

    // A plain member has no business in the debt book at all.
    $member = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$member->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $member->assignRole('member');

    $this->actingAs($member);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    expect(CustomerDebtResource::canAccess())->toBeFalse();
});

it('is never creatable, editable or deletable', function () {
    expect(CustomerDebtResource::canCreate())->toBeFalse()
        ->and(CustomerDebtResource::canEdit(new PosCustomer()))->toBeFalse()
        ->and(CustomerDebtResource::canDelete(new PosCustomer()))->toBeFalse();
});
