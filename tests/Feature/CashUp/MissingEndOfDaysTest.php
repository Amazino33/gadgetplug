<?php

// Days a cashier opened or sold on but never counted, and what happens when
// they try to start a new day with some still outstanding.

use App\Filament\Vendor\Resources\CashUps\Widgets\MissingEndOfDaysWidget;
use App\Models\PosMissingDayReason;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use App\Services\Cash\MissingEndOfDays;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Cash/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Midday in Lagos on Tuesday 6 October.
    Carbon::setTestNow('2026-10-06 11:00:00');
    $this->ctx = cashUpContext();
    $this->ctx['vendor']->users()->syncWithoutDetaching([$this->ctx['cashier']->id]);
});

afterEach(fn () => Carbon::setTestNow());

function missingSale(array $ctx, string $at, float $total = 10000, array $over = []): PosSale
{
    return PosSale::create(array_merge([
        'reference' => 'POS-'.Str::random(10), 'vendor_id' => $ctx['vendor']->id,
        'store_id' => $ctx['store']->id, 'cashier_id' => $ctx['cashier']->id,
        'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => $total,
        'payment_method' => 'cash', 'amount_tendered' => $total, 'change_given' => 0,
        'status' => 'completed', 'completed_at' => $at,
    ], $over));
}

function missingFor(array $ctx)
{
    return app(MissingEndOfDays::class)->forCashier($ctx['vendor']->id, $ctx['store']->id, $ctx['cashier']->id);
}

// ── Which days are missing ───────────────────────────────────────────────────

test('a day with sales and no record is missing', function () {
    // Zeelink Phones' Saturday: sold on, never opened, never counted.
    missingSale($this->ctx, '2026-10-03 14:00:00', 25000);

    $missing = missingFor($this->ctx);

    expect($missing)->toHaveCount(1)
        ->and($missing[0]['business_date'])->toBe('2026-10-03')
        ->and($missing[0]['session'])->toBeNull()
        ->and($missing[0]['sales_count'])->toBe(1)
        ->and($missing[0]['sales_total'])->toBe(25000.0);
});

test('a day opened and never counted is missing, whichever device opened it', function () {
    $open = openCashUp($this->ctx, ['business_date' => '2026-10-05', 'opening_float' => 5000]);

    $missing = missingFor($this->ctx);

    expect($missing)->toHaveCount(1)
        ->and($missing[0]['session'])->toBe(['id' => $open->id, 'opening_float' => 5000.0]);
});

test('a counted day is not missing', function () {
    missingSale($this->ctx, '2026-10-05 10:00:00');
    closeCashUp(openCashUp($this->ctx, ['business_date' => '2026-10-05']));

    expect(missingFor($this->ctx))->toBeEmpty();
});

test('today is never missing — it is still being traded', function () {
    missingSale($this->ctx, '2026-10-06 09:00:00');
    openCashUp($this->ctx, ['business_date' => '2026-10-06']);

    expect(missingFor($this->ctx))->toBeEmpty();
});

test('it looks back fourteen days and no further', function () {
    missingSale($this->ctx, '2026-09-22 10:00:00');
    missingSale($this->ctx, '2026-09-21 10:00:00');

    expect(missingFor($this->ctx)->pluck('business_date')->all())->toBe(['2026-09-22']);
});

test('a voided sale does not make a day missing', function () {
    missingSale($this->ctx, '2026-10-05 10:00:00', 10000, ['status' => 'voided']);

    expect(missingFor($this->ctx))->toBeEmpty();
});

test('another cashier days are theirs, not this cashier', function () {
    $mate = User::factory()->create();
    missingSale($this->ctx, '2026-10-05 10:00:00', 10000, ['cashier_id' => $mate->id]);

    expect(missingFor($this->ctx))->toBeEmpty();
});

// ── The till ─────────────────────────────────────────────────────────────────

test('the till is told which days are missing', function () {
    Sanctum::actingAs($this->ctx['cashier']);
    missingSale($this->ctx, '2026-10-03 14:00:00');

    $this->getJson('/api/pos/sessions/active?vendor_id='.$this->ctx['vendor']->id)
        ->assertOk()
        ->assertJsonPath('missing.0.business_date', '2026-10-03');
});

test('a missing day can be opened and counted after the fact', function () {
    Sanctum::actingAs($this->ctx['cashier']);
    missingSale($this->ctx, '2026-10-03 14:00:00', 25000);

    $id = $this->postJson('/api/pos/sessions/open', [
        'vendor_id' => $this->ctx['vendor']->id, 'opening_float' => 0, 'business_date' => '2026-10-03',
    ])->assertCreated()->json('session.id');

    $this->postJson("/api/pos/sessions/{$id}/close", [
        'vendor_id' => $this->ctx['vendor']->id, 'counted_cash' => 25000, 'counted_terminal' => 0,
        'business_date' => '2026-10-03',
    ])->assertOk();

    expect((float) PosSession::find($id)->cash_variance)->toBe(0.0)
        ->and(missingFor($this->ctx))->toBeEmpty();
});

test('a reason for putting a day off arrives with the morning open', function () {
    Sanctum::actingAs($this->ctx['cashier']);
    missingSale($this->ctx, '2026-10-03 14:00:00');

    $payload = [
        'vendor_id' => $this->ctx['vendor']->id, 'opening_float' => 0,
        'missing_day_reasons' => [['business_date' => '2026-10-03', 'reason' => 'Manager has the drawer key']],
    ];

    $this->postJson('/api/pos/sessions/open', $payload)->assertCreated();
    // The till replays an open it never heard back from. Once is enough.
    $this->postJson('/api/pos/sessions/open', $payload)->assertOk();

    expect(PosMissingDayReason::count())->toBe(1)
        ->and(missingFor($this->ctx)[0]['reasons'][0])->toBe([
            'given_on' => '2026-10-06', 'reason' => 'Manager has the drawer key',
        ]);
});

test('a reason must actually say something', function () {
    Sanctum::actingAs($this->ctx['cashier']);

    $this->postJson('/api/pos/sessions/open', [
        'vendor_id' => $this->ctx['vendor']->id, 'opening_float' => 0,
        'missing_day_reasons' => [['business_date' => '2026-10-03', 'reason' => '']],
    ])->assertStatus(422);
});

// ── The panel ────────────────────────────────────────────────────────────────

function missingPanelAs(array $ctx, User $user): void
{
    cashRoles($ctx['vendor']);
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);
}

test('the manager sees missing days, by cashier, with the reason given', function () {
    missingSale($this->ctx, '2026-10-03 14:00:00', 25000);
    PosMissingDayReason::create([
        'vendor_id' => $this->ctx['vendor']->id, 'store_id' => $this->ctx['store']->id,
        'cashier_id' => $this->ctx['cashier']->id, 'business_date' => '2026-10-03',
        'given_on' => '2026-10-06', 'reason' => 'Manager has the drawer key',
    ]);

    missingPanelAs($this->ctx, $this->ctx['owner']);

    Livewire::test(MissingEndOfDaysWidget::class)
        ->assertSee('1 day has no End of Day record')
        ->assertSee($this->ctx['cashier']->name)
        ->assertSee('Manager has the drawer key');
});

test('a cashier reading their own history sees only their own gaps', function () {
    $mate = User::factory()->create(['name' => 'Someone Else']);
    missingSale($this->ctx, '2026-10-03 14:00:00', 10000, ['cashier_id' => $mate->id]);

    missingPanelAs($this->ctx, $this->ctx['cashier']);

    Livewire::test(MissingEndOfDaysWidget::class)->assertDontSee('Someone Else');
});
