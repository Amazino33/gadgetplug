<?php

use App\Filament\Vendor\Resources\CashUps\CashUpResource;
use App\Filament\Vendor\Resources\CashUps\Pages\ListCashUps;
use App\Models\AccountabilityLedgerEntry;
use App\Models\PosSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Cash/Helpers.php';

uses(RefreshDatabase::class);

/** Sign in to the vendor panel, with permissions seeded as production has them. */
function cashUpPanel(array $ctx, User $user): void
{
    cashRoles($ctx['vendor']);
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);
}

/** A manager: on the team, holding receive_cash, and not the cashier. */
function cashUpManager(array $ctx): User
{
    cashRoles($ctx['vendor']);
    $manager = User::factory()->create(['name' => 'Manager']);
    $ctx['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $manager->assignRole('store_admin');

    return $manager;
}

function reviewable(array $ctx, array $over = []): PosSession
{
    return closeCashUp(
        openCashUp($ctx, ['business_date' => now()->toDateString()]),
        array_merge([
            'expected_cash' => 100000, 'counted_cash' => 95000, 'cash_variance' => -5000,
            'expected_terminal' => 0, 'counted_terminal' => 0, 'terminal_variance' => 0,
            'breakdown' => [
                'expected_cash' => 100000,
                'cash_lines' => [
                    ['key' => 'opening_float', 'label' => 'Opening float', 'amount' => 20000],
                    ['key' => 'cash_sales', 'label' => 'Cash sales', 'amount' => 80000],
                ],
                'terminal_lines' => [],
                'context' => ['sales_count' => 8, 'gross_sales' => 80000, 'debt_rung' => 0],
                'warnings' => [],
            ],
        ], $over),
    );
}

// ── The queue ────────────────────────────────────────────────────────────────

test('a manager sees the days waiting for review', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    reviewable($ctx);
    cashUpPanel($ctx, $manager);

    Livewire::test(ListCashUps::class)
        ->assertOk()
        ->assertSee('Nkechi')
        ->assertSee('waiting to be reviewed');
});

test('the page says which branch the figures are for', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    reviewable($ctx);
    cashUpPanel($ctx, $manager);

    // A single-branch vendor: the branch is named, and there is nothing being
    // left out, so nothing is claimed about other branches.
    $subheading = (string) Livewire::test(ListCashUps::class)
        ->assertOk()
        ->instance()
        ->getSubheading();

    expect($subheading)->toContain($ctx['store']->name)
        ->and($subheading)->toContain('1 waiting to be reviewed')
        ->and($subheading)->not->toContain('not included');
});

test('a branch-assigned manager is told which branches are left out', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    reviewable($ctx);

    App\Models\Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    // Assigned to one counter, so that is all they are looking at — and they
    // have no way to know unless the page says so.
    $manager->stores()->syncWithoutDetaching([$ctx['store']->id]);
    cashUpPanel($ctx, $manager);

    expect((string) Livewire::test(ListCashUps::class)->instance()->getSubheading())
        ->toContain($ctx['store']->name)
        ->toContain('1 other branch is not included');
});

test('even an owner is looking at one branch at a time', function () {
    $ctx = cashUpContext();
    reviewable($ctx);

    App\Models\Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    cashUpPanel($ctx, $ctx['owner']);

    // An owner reaches every branch but the panel still stands in one of them,
    // so this table is that branch's takings and not the business's. Saying so
    // is the difference between an owner knowing a shortage exists elsewhere and
    // never going to look.
    expect((string) Livewire::test(ListCashUps::class)->instance()->getSubheading())
        ->toContain($ctx['store']->name)
        ->toContain('1 other branch is not included');
});

test('the badge counts only what is waiting', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    reviewable($ctx);
    cashUpPanel($ctx, $manager);

    expect(CashUpResource::getNavigationBadge())->toBe('1');
});

// ── Who may reach it ─────────────────────────────────────────────────────────

test('a cashier sees their own day but not the till next to them', function () {
    $ctx = cashUpContext();
    $mine = reviewable($ctx);

    $mate = User::factory()->create(['name' => 'Tunde']);
    $ctx['vendor']->users()->syncWithoutDetaching([$mate->id]);
    $theirs = closeCashUp(openCashUp($ctx, [
        'cashier_id' => $mate->id, 'business_date' => now()->toDateString(),
    ]));

    cashUpPanel($ctx, $ctx['cashier']);

    // Being told you are short without being allowed to see the working is not
    // accountability — so their own row is visible.
    Livewire::test(ListCashUps::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

test('a plain member with no cash-up of their own cannot reach the screen', function () {
    $ctx = cashUpContext();
    cashRoles($ctx['vendor']);
    $outsider = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$outsider->id]);

    cashUpPanel($ctx, $outsider);

    expect(CashUpResource::canAccess())->toBeFalse();
});

test('nothing on this screen can create or edit a cash-up', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    $session = reviewable($ctx);
    cashUpPanel($ctx, $manager);

    // A manager who could type a count would make the count worthless.
    expect(CashUpResource::canCreate())->toBeFalse()
        ->and($manager->can('update', $session))->toBeFalse()
        ->and($manager->can('delete', $session))->toBeFalse();
});

// ── The policy is the gate, not the button ───────────────────────────────────

test('a cashier cannot approve their own day even by reaching for the action', function () {
    $ctx = cashUpContext();
    $session = reviewable($ctx);

    // Give the cashier the reviewing permission outright. The self-dealing rule
    // must still hold — this is the case the whole control exists for.
    cashRoles($ctx['vendor']);
    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['cashier']->assignRole('store_admin');

    cashUpPanel($ctx, $ctx['cashier']);

    expect($ctx['cashier']->can('approve', $session))->toBeFalse()
        ->and($ctx['cashier']->can('rectify', $session))->toBeFalse();

    // Reaching for the action directly, not merely failing to see the button.
    // Filament refuses to resolve an unauthorised action at all, which is the
    // point — there is nothing there to call.
    try {
        Livewire::test(ListCashUps::class)->callTableAction('approve', $session);
    } catch (Throwable) {
        // Refused, as it must be.
    }

    expect($session->refresh()->isPendingReview())->toBeTrue()
        ->and(AccountabilityLedgerEntry::count())->toBe(0);
});

test('an owner who also stands at the counter cannot approve their own day', function () {
    $ctx = cashUpContext();
    $owner = $ctx['owner'];

    // The owner rang the sales themselves. Ownership must not override this.
    $session = closeCashUp(openCashUp($ctx, [
        'cashier_id' => $owner->id, 'business_date' => now()->toDateString(),
    ]));

    cashUpPanel($ctx, $owner);

    expect($owner->can('approve', $session))->toBeFalse();
});

test('a manager may approve somebody else day', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    $session = reviewable($ctx);
    cashUpPanel($ctx, $manager);

    expect($manager->can('approve', $session))->toBeTrue()
        ->and($manager->can('rectify', $session))->toBeTrue();
});

// ── Reviewing end to end ─────────────────────────────────────────────────────

test('a manager explains part of a gap and approves the rest', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    $session = reviewable($ctx);
    cashUpPanel($ctx, $manager);

    Livewire::test(ListCashUps::class)
        ->callTableAction('rectify', $session, [
            'kind' => 'expense', 'amount' => 3000, 'note' => 'Transport for the driver',
        ])
        ->assertHasNoErrors();

    $session->refresh()->load('rectifications');
    expect($session->resolvedCashVariance())->toBe(-2000.0);

    Livewire::test(ListCashUps::class)
        ->callTableAction('approve', $session, ['notes' => 'Counted with me present.'])
        ->assertHasNoErrors();

    expect($session->refresh()->isApproved())->toBeTrue()
        // Only the part nobody could account for reaches the ledger.
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(2000.0);
});

test('an approved day offers nothing further to do', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    $session = reviewable($ctx);
    cashUpPanel($ctx, $manager);

    Livewire::test(ListCashUps::class)->callTableAction('approve', $session);
    $session->refresh();

    // Approval is the end of it. Reopening a signed-off day, or explaining more
    // of it afterwards, would rework a figure somebody has already stood behind.
    expect($session->isApproved())->toBeTrue()
        ->and($manager->can('approve', $session))->toBeFalse()
        ->and($manager->can('rectify', $session))->toBeFalse()
        ->and($session->acceptsRectifications())->toBeFalse();
});

// ── The working ──────────────────────────────────────────────────────────────
//
// Rendered directly rather than through a mounted modal: the modal is Filament's
// job and is covered by the action existing, whereas what actually has to be
// right is that this template shows a cashier the arithmetic they are held to.

function renderWorking(PosSession $session): string
{
    return view('filament.vendor.cash-up-breakdown', [
        'session' => $session->load('rectifications.creator', 'rectifications.relatedSale'),
    ])->render();
}

test('the working shows the float as its own line', function () {
    $ctx = cashUpContext();

    // "Why is the drawer more than my sales?" is the first question asked, and
    // the float is the answer.
    $html = renderWorking(reviewable($ctx));

    expect($html)->toContain('Opening float')
        ->and($html)->toContain('Cash sales')
        ->and($html)->toContain('₦20,000.00')
        ->and($html)->toContain('Should have been')
        ->and($html)->toContain('Still missing')
        ->and($html)->toContain('₦5,000.00');
});

test('the working shows what has already been accounted for', function () {
    $ctx = cashUpContext();
    $manager = cashUpManager($ctx);
    $session = reviewable($ctx);

    app(App\Actions\CashUp\RecordRectificationAction::class)->execute(
        session: $session, manager: $manager, kind: 'expense',
        amount: 3000, note: 'Transport for the driver',
    );

    $html = renderWorking($session->refresh());

    expect($html)->toContain('What has been accounted for')
        ->and($html)->toContain('Spent out of the drawer')
        ->and($html)->toContain('Transport for the driver')
        // And the remaining gap, not the one first presented.
        ->and($html)->toContain('₦2,000.00');
});

test('the working explains credit rather than letting the figures look wrong', function () {
    $ctx = cashUpContext();
    $session = reviewable($ctx, [
        'breakdown' => [
            'cash_lines' => [['key' => 'cash_sales', 'label' => 'Cash sales', 'amount' => 60000]],
            'terminal_lines' => [],
            'context' => ['sales_count' => 9, 'gross_sales' => 100000, 'debt_rung' => 40000],
            'warnings' => [],
        ],
    ]);

    $html = renderWorking($session);

    expect($html)->toContain('Sold on credit')
        ->and($html)->toContain('₦40,000.00')
        ->and($html)->toContain("Money sold on credit is in nobody's hands");
});

test('a warning on the snapshot is shown to the manager', function () {
    $ctx = cashUpContext();
    $session = reviewable($ctx, [
        'breakdown' => [
            'cash_lines' => [], 'terminal_lines' => [], 'context' => [],
            'warnings' => ['2 sale(s) this cashier rang today are not assigned to any branch.'],
        ],
    ]);

    // A shortage caused by a data gap must never be put to a cashier as if it
    // were missing money.
    $html = renderWorking($session);

    expect($html)->toContain('Read this before trusting the figures')
        ->and($html)->toContain('not assigned to any branch');
});

test('the cashier own words reach the manager', function () {
    $ctx = cashUpContext();
    $session = reviewable($ctx, ['notes' => 'Gave 3,000 to the driver for transport.']);

    $html = renderWorking($session);

    expect($html)->toContain('What the cashier said')
        ->and($html)->toContain('Gave 3,000 to the driver');
});
