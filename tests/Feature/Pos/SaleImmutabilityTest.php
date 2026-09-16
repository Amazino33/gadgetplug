<?php

use App\Actions\Pos\RecordSaleReversalAction;
use App\Models\PosSale;
use App\Models\PosSaleReversal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/../Cash/Helpers.php';

uses(RefreshDatabase::class);

/**
 * The guardrail the whole reconciliation rests on.
 *
 * Every figure a settlement statement puts in front of a storekeeper is a sum
 * over completed sales. If one can be edited afterwards, the shortage it would
 * have shown can be edited away with it, and the statement accuses nobody of
 * anything. These tests are about that, not about voiding.
 */
function immutabilityContext(): array
{
    $vendor  = cashVendor();
    $store   = $vendor->defaultStore;
    $cashier = User::factory()->create();

    return [
        'vendor'  => $vendor,
        'store'   => $store,
        'cashier' => $cashier,
        'sale'    => cashSale($vendor, $store, $cashier->id, ['total' => 40000]),
    ];
}

test('a rung sale cannot be edited', function () {
    $ctx = immutabilityContext();

    expect(fn () => $ctx['sale']->update(['total' => 1]))
        ->toThrow(LogicException::class);

    expect((float) $ctx['sale']->fresh()->total)->toBe(40000.00);
});

test('a rung sale cannot have its status rewritten directly', function () {
    $ctx = immutabilityContext();

    // The exact move this guard exists to stop: making a cash sale stop
    // counting towards expected cash without leaving a record of who did it.
    expect(fn () => $ctx['sale']->update(['status' => 'voided']))
        ->toThrow(LogicException::class);

    expect($ctx['sale']->fresh()->status)->toBe('completed')
        ->and(PosSaleReversal::count())->toBe(0);
});

test('a rung sale cannot be deleted', function () {
    $ctx = immutabilityContext();

    expect(fn () => $ctx['sale']->delete())->toThrow(LogicException::class);

    expect(PosSale::whereKey($ctx['sale']->id)->exists())->toBeTrue();
});

test('voiding records who withdrew the sale, and why, before the status moves', function () {
    $ctx = immutabilityContext();

    $reversal = app(RecordSaleReversalAction::class)->void(
        sale:   $ctx['sale'],
        actor:  $ctx['cashier'],
        reason: 'Rang it twice',
    );

    expect($ctx['sale']->fresh()->status)->toBe('voided')
        ->and($reversal->type)->toBe(PosSaleReversal::TYPE_VOID)
        ->and($reversal->from_status)->toBe('completed')
        ->and($reversal->to_status)->toBe('voided')
        ->and((float) $reversal->amount)->toBe(40000.00)
        ->and($reversal->reason)->toBe('Rang it twice')
        ->and($reversal->performed_by)->toBe($ctx['cashier']->id);
});

test('the status column is only ever a mirror of the reversal journal', function () {
    $ctx = immutabilityContext();

    expect($ctx['sale']->derivedStatus())->toBe('completed');

    app(RecordSaleReversalAction::class)->void($ctx['sale'], $ctx['cashier'], 'Duplicate');

    $fresh = $ctx['sale']->fresh()->load('reversals');

    // If these ever disagree, something wrote to the sale outside the reversal
    // path and every figure derived from it is suspect.
    expect($fresh->derivedStatus())->toBe($fresh->status);
});

test('a void needs a stated reason', function () {
    $ctx = immutabilityContext();

    expect(fn () => app(RecordSaleReversalAction::class)->void($ctx['sale'], $ctx['cashier'], ''))
        ->toThrow(RuntimeException::class);

    expect($ctx['sale']->fresh()->status)->toBe('completed');
});

test('the same sale cannot be voided twice', function () {
    $ctx = immutabilityContext();

    app(RecordSaleReversalAction::class)->void($ctx['sale'], $ctx['cashier'], 'First');

    expect(fn () => app(RecordSaleReversalAction::class)->void($ctx['sale']->fresh(), $ctx['cashier'], 'Again'))
        ->toThrow(RuntimeException::class);

    expect(PosSaleReversal::where('pos_sale_id', $ctx['sale']->id)->count())->toBe(1);
});

test('a reversal is itself append-only', function () {
    $ctx = immutabilityContext();

    $reversal = app(RecordSaleReversalAction::class)->void($ctx['sale'], $ctx['cashier'], 'Wrong item');

    expect(fn () => $reversal->update(['reason' => 'Something more flattering']))
        ->toThrow(LogicException::class);

    expect(fn () => $reversal->delete())->toThrow(LogicException::class);

    expect($reversal->fresh()->reason)->toBe('Wrong item');
});

test('a voided cash sale stops counting towards what the cashier owes', function () {
    $ctx = immutabilityContext();

    expect(App\Services\Cash\CashDrawer::expectedFrom(
        $ctx['vendor']->id, $ctx['store']->id, $ctx['cashier']->id
    ))->toBe(40000.0);

    app(RecordSaleReversalAction::class)->void($ctx['sale'], $ctx['cashier'], 'Rang in error');

    // Correct, but it is exactly why the reversal row has to exist: this is the
    // shape of both an honest mistake and a theft, and only the journal beside
    // it can tell the settlement statement which one to show.
    expect(App\Services\Cash\CashDrawer::expectedFrom(
        $ctx['vendor']->id, $ctx['store']->id, $ctx['cashier']->id
    ))->toBe(0.0);

    expect(PosSaleReversal::where('store_id', $ctx['store']->id)->sum('amount'))
        ->toEqual(40000.00);
});
