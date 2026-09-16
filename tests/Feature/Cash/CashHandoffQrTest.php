<?php

use App\Actions\Cash\RedeemCashHandoffAction;
use App\Actions\Cash\SubmitCashAction;
use App\Models\CashSubmission;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\StorePermission;
use App\Services\Cash\CashHandoffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * A handover is worth exactly as much as the separation between the two people
 * on it. These tests are about that separation, not about the QR.
 *
 * handoffContext() and issueHandoff() live in Helpers.php so the page tests can
 * share them — Pest only sees a function from a sibling test file when that
 * file happens to load first.
 */
test('a code can be looked at without being spent', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    // The receiver has to see what is being claimed before they can agree.
    expect(app(RedeemCashHandoffAction::class)->inspect($token)->id)->toBe($submission->id)
        ->and(app(RedeemCashHandoffAction::class)->inspect($token)->id)->toBe($submission->id);
});

test('a code works once and is dead afterwards', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    app(RedeemCashHandoffAction::class)->confirm($token, $ctx['collector']);

    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $ctx['collector']))
        ->toThrow(RuntimeException::class, 'expired');
});

test('a code stops working once it has expired', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $this->travel(CashHandoffToken::TTL_MINUTES + 1)->minutes();

    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $ctx['collector']))
        ->toThrow(RuntimeException::class, 'expired');
});

test('confirming stamps whoever actually answered, not whoever was guessed at', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $confirmed = app(RedeemCashHandoffAction::class)->confirm($token, $ctx['collector']);

    expect($confirmed->status)->toBe(CashSubmission::STATUS_CONFIRMED)
        ->and($confirmed->received_by)->toBe($ctx['collector']->id)
        ->and($confirmed->confirmed_at)->not->toBeNull();
});

test('the person who handed the cash over cannot sign for receiving it', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    // The whole control, in one assertion.
    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $ctx['keeper']))
        ->toThrow(RuntimeException::class, 'cannot sign for cash you handed over yourself');

    expect(CashSubmission::first()->status)->toBe(CashSubmission::STATUS_PENDING);
});

test('somebody without permission to receive cash here cannot answer', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $passerby = User::factory()->create();

    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $passerby))
        ->toThrow(RuntimeException::class, 'not permitted');
});

test('permission to receive cash at one branch does not carry to another', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $otherBranch = Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    $elsewhere = User::factory()->create();
    $elsewhere->stores()->attach($otherBranch->id);
    setPermissionsTeamId($ctx['vendor']->id);
    $elsewhere->givePermissionTo('receive_cash');

    // Holds the permission vendor-wide, but was never standing at this branch.
    expect(StorePermission::allows($elsewhere, $ctx['vendor']->id, $otherBranch->id, 'receive_cash'))->toBeTrue()
        ->and(StorePermission::allows($elsewhere, $ctx['vendor']->id, $ctx['store']->id, 'receive_cash'))->toBeFalse();

    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $elsewhere))
        ->toThrow(RuntimeException::class, 'not permitted');
});

test('receiving cash is a separate permission from changing the books', function () {
    $ctx = handoffContext();

    setPermissionsTeamId($ctx['vendor']->id);

    // Segregation of duties: the collector can take the money but cannot reach
    // the records that would let a shortfall be tidied away afterwards.
    expect($ctx['collector']->hasPermissionTo('receive_cash'))->toBeTrue()
        ->and($ctx['collector']->hasPermissionTo('void_sale'))->toBeFalse();

    // And the storekeeper who takes the cash in cannot sign it off.
    expect($ctx['keeper']->hasPermissionTo('submit_cash'))->toBeTrue()
        ->and($ctx['keeper']->hasPermissionTo('receive_cash'))->toBeFalse();
});

test('a disputed handover keeps both figures and settles nothing on its own', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $disputed = app(RedeemCashHandoffAction::class)->dispute(
        $token, $ctx['collector'], 'Only 45,000 in the envelope', 45000,
    );

    expect($disputed->status)->toBe(CashSubmission::STATUS_DISPUTED)
        ->and((float) $disputed->amount)->toBe(50000.00)
        ->and((float) $disputed->disputed_amount)->toBe(45000.00)
        ->and($disputed->dispute_note)->toBe('Only 45,000 in the envelope');
});

test('a nominated receiver is the only one who can answer', function () {
    $ctx = handoffContext();

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['keeper'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );
    $token = CashHandoffToken::issue($submission);

    // Naming somebody is a deliberate narrowing, so permission alone is not
    // enough for anyone else.
    expect(fn () => app(RedeemCashHandoffAction::class)->confirm($token, $ctx['collector']))
        ->toThrow(RuntimeException::class, 'Only the person it was handed to');

    expect(app(RedeemCashHandoffAction::class)->confirm($token, $ctx['owner'])->status)
        ->toBe(CashSubmission::STATUS_CONFIRMED);
});
