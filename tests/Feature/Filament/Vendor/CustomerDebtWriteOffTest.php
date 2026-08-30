<?php

use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Actions\Pos\WriteOffCustomerDebtAction;
use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Filament\Vendor\Resources\CustomerDebts\Pages\ListCustomerDebts;
use App\Models\FinancialLedgerEntry;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;
use App\Policies\PosCustomerDebtPolicy;
use App\Services\Pos\CustomerDebtService;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A debt whose credit sale was rung up by somebody OTHER than the owner, so the
 * owner may write it off. The self-dealing case is exercised separately.
 */
function writeOffContext(float $owed = 10000.0): array
{
    $ctx = repaymentContext(0);

    $cashier = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$cashier->id]);

    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $ctx['customer']->id,
        'vendor_id'       => $ctx['vendor']->id,
        'direction'       => 'charge',
        'amount'          => $owed,
        'store_id'        => $ctx['store']->id,
        'created_by'      => $cashier->id,
        'occurred_at'     => '2026-08-01',
        'description'     => 'Credit sale — seed',
    ]);

    return $ctx + ['cashier' => $cashier];
}

// ─── Effect ─────────────────────────────────────────────────────────────

it('closes the balance', function () {
    $ctx = writeOffContext();

    app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Moved away, uncontactable',
    );

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($ctx['customer']->id))->toBe(0.0)
        ->and($debt->owesAnything($ctx['customer']->id))->toBeFalse()
        ->and($debt->totalWrittenOff($ctx['customer']->id))->toBe(10000.0);
});

it('writes off only what is left after part payment', function () {
    $ctx = writeOffContext();

    app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Gave up chasing the rest',
    );

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($ctx['customer']->id))->toBe(0.0)
        ->and($debt->totalPaid($ctx['customer']->id))->toBe(4000.0)
        ->and($debt->totalWrittenOff($ctx['customer']->id))->toBe(6000.0);
});

it('leaves the loss standing rather than posting it twice', function () {
    $ctx = writeOffContext();

    app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Bad debt',
    );

    // Nothing is posted to the financial ledger, and that is deliberate. The
    // credit sale never recognised revenue while the cost booked at stock-out,
    // so the loss is already in the books — posting here would count it twice.
    expect(FinancialLedgerEntry::count())->toBe(0);
});

it('keeps the whole history readable afterwards', function () {
    $ctx = writeOffContext();

    app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Uncontactable',
    );

    $history = app(CustomerDebtService::class)->history($ctx['customer']->id);

    // The charge is still there — forgiving a debt records what happened, it
    // does not erase what was sold.
    expect($history)->toHaveCount(2)
        ->and($history->first()['entry']->isCharge())->toBeTrue()
        ->and($history->last()['entry']->isWriteoff())->toBeTrue()
        ->and($history->last()['running'])->toBe(0.0)
        ->and($history->last()['entry']->description)->toContain('Uncontactable');
});

it('records who decided and why', function () {
    $ctx = writeOffContext();

    $row = app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Long overdue', storeId: $ctx['store']->id,
    );

    expect($row->created_by)->toBe($ctx['owner']->id)
        ->and($row->store_id)->toBe($ctx['store']->id)
        ->and($row->description)->toBe('Written off — Long overdue');
});

it('is append-only like every other row', function () {
    $ctx = writeOffContext();

    $row = app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Bad debt',
    );

    expect(fn () => $row->update(['amount' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

// ─── Authorisation, at the policy ───────────────────────────────────────

it('refuses a non-owner at the policy, not merely in the UI', function () {
    $ctx = writeOffContext();
    (new VendorPermissionsSeeder())->run();
    VendorRoles::seedFor($ctx['vendor']);

    $admin = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$admin->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $admin->assignRole('store_admin');

    expect(app(PosCustomerDebtPolicy::class)->writeOff($admin, $ctx['customer']))->toBeFalse();

    // And calling the action directly is refused too — the check is not the
    // button's job.
    expect(fn () => app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $admin, reason: 'Trying it on',
    ))->toThrow(RuntimeException::class);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);
});

it('refuses a member of another vendor entirely', function () {
    $mine   = writeOffContext();
    $theirs = writeOffContext();

    expect(app(PosCustomerDebtPolicy::class)->writeOff($theirs['owner'], $mine['customer']))->toBeFalse();
});

it('blocks the person who granted the credit from clearing it', function () {
    $ctx = writeOffContext();

    // The cashier rang up the credit sale. Even made an owner, they may not
    // forgive their own credit — that is the "sell to a friend, then write it
    // off" path, and it is the whole reason this rule exists.
    $ctx['vendor']->update(['user_id' => $ctx['cashier']->id]);

    expect(app(PosCustomerDebtPolicy::class)->writeOff($ctx['cashier']->fresh(), $ctx['customer']))->toBeFalse();

    expect(fn () => app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['cashier']->fresh(), reason: 'Clearing my own',
    ))->toThrow(RuntimeException::class);
});

it('blocks it even when only one of several charges was theirs', function () {
    $ctx = writeOffContext();

    // A second charge from someone else does not launder the first.
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $ctx['customer']->id,
        'vendor_id'       => $ctx['vendor']->id,
        'direction'       => 'charge',
        'amount'          => 5000,
        'created_by'      => $ctx['owner']->id,
        'occurred_at'     => '2026-08-05',
    ]);

    $ctx['vendor']->update(['user_id' => $ctx['cashier']->id]);

    expect(app(PosCustomerDebtPolicy::class)->writeOff($ctx['cashier']->fresh(), $ctx['customer']))->toBeFalse();
});

// ─── Guards ─────────────────────────────────────────────────────────────

it('refuses when there is nothing owed', function () {
    $ctx = writeOffContext();

    app(RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 10000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    expect(app(PosCustomerDebtPolicy::class)->writeOff($ctx['owner'], $ctx['customer']))->toBeFalse();
});

it('insists on a reason', function () {
    $ctx = writeOffContext();

    expect(fn () => app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: '   ',
    ))->toThrow(RuntimeException::class);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);
});

// ─── Panel ──────────────────────────────────────────────────────────────

it('drops the customer off the debt list once written off', function () {
    $ctx = writeOffContext();
    actAsDebtOwner($ctx);

    expect(CustomerDebtResource::getEloquentQuery()->count())->toBe(1);

    app(WriteOffCustomerDebtAction::class)->execute(
        customer: $ctx['customer'], decidedBy: $ctx['owner'], reason: 'Bad debt',
    );

    expect(CustomerDebtResource::getEloquentQuery()->count())->toBe(0);
});

it('writes off through the panel action', function () {
    $ctx = writeOffContext();
    actAsDebtOwner($ctx);

    Livewire::test(ListCustomerDebts::class)
        ->callTableAction('writeOff', $ctx['customer'], data: ['reason' => 'Shop closed down'])
        ->assertHasNoTableActionErrors();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);

    expect(PosCustomerLedgerEntry::where('direction', 'writeoff')->first()->description)
        ->toContain('Shop closed down');
});

it('hides the write-off button from someone who may not use it', function () {
    $ctx = writeOffContext();
    (new VendorPermissionsSeeder())->run();
    VendorRoles::seedFor($ctx['vendor']);

    $admin = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$admin->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $admin->assignRole('store_admin');

    // Assigned to the branch the credit was given at: everyone but the owner is
    // now scoped to their current store, so an unassigned user sees no rows at
    // all and the action could not be found either way.
    $admin->stores()->syncWithoutDetaching([$ctx['store']->id]);

    $this->actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);
    App\Services\ActiveStore::set($ctx['vendor'], $admin, $ctx['store']);

    Livewire::test(ListCustomerDebts::class)
        ->assertTableActionHidden('writeOff', $ctx['customer']);
});
