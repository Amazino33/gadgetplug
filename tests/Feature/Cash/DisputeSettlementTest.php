<?php

use App\Actions\Cash\ResolveCashSubmissionAction;
use App\Actions\Cash\SubmitCashAction;
use App\Models\AccountabilityLedgerEntry;
use App\Models\CashSubmission;
use App\Models\User;
use App\Services\Cash\CashDrawer;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * A dispute used to be recordable but never settleable — the row sat contested
 * forever and the money stayed on the submitter with no way to close it. These
 * cover the conversation that ends it, and who is allowed to have it.
 */
function disputedHandover(float $claimed = 50000, float $received = 42000): array
{
    $ctx = handoffContext();

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['keeper'], receiver: $ctx['collector'], store: $ctx['store'], amount: $claimed,
    );

    app(ResolveCashSubmissionAction::class)->dispute(
        $submission, $ctx['collector'], 'Envelope was light', $received,
    );

    $ctx['submission'] = $submission->fresh();

    return $ctx;
}

test('while a dispute is open the money stays on the person who handed it over', function () {
    $ctx = disputedHandover();

    // Denying receipt must never be the easy way to clear a balance.
    expect(CashDrawer::expectedFrom($ctx['vendor']->id, $ctx['store']->id, $ctx['keeper']->id))
        ->toBe(50000.0);
});

test('accepting the claim credits the submitter in full', function () {
    $ctx = disputedHandover();

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_ACCEPTED, 'Recounted together, it was all there',
    );

    expect($ctx['submission']->fresh()->status)->toBe(CashSubmission::STATUS_RESOLVED)
        ->and(CashDrawer::expectedFrom($ctx['vendor']->id, $ctx['store']->id, $ctx['keeper']->id))->toBe(0.0);
});

test('charging the difference credits only what arrived and puts the rest on the submitter', function () {
    $ctx = disputedHandover(claimed: 50000, received: 42000);

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_CHARGED, 'Short by 8,000',
    );

    // Credited with the 42,000 both sides agree arrived.
    expect(CashDrawer::expectedFrom($ctx['vendor']->id, $ctx['store']->id, $ctx['keeper']->id))
        ->toBe(8000.0);

    // And the 8,000 is now a debt on the same ledger a stock shortage uses.
    expect((float) AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)->sum('amount'))
        ->toBe(8000.0);

    expect(AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)->value('store_id'))
        ->toBe($ctx['store']->id);
});

test('writing the difference off credits what arrived and charges nobody', function () {
    $ctx = disputedHandover(claimed: 50000, received: 42000);

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_WRITTEN_OFF, 'Not worth chasing',
    );

    expect(CashDrawer::expectedFrom($ctx['vendor']->id, $ctx['store']->id, $ctx['keeper']->id))->toBe(8000.0)
        ->and(AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)->count())->toBe(0);
});

test('settling never rewrites what either person said at the time', function () {
    $ctx = disputedHandover(claimed: 50000, received: 42000);

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_CHARGED, 'Short',
    );

    $fresh = $ctx['submission']->fresh();

    expect((float) $fresh->amount)->toBe(50000.00)
        ->and((float) $fresh->disputed_amount)->toBe(42000.00)
        ->and($fresh->dispute_note)->toBe('Envelope was light')
        ->and($fresh->resolved_by)->toBe($ctx['owner']->id);
});

test('the person who handed the cash over cannot settle the argument about it', function () {
    $ctx = disputedHandover();

    expect(fn () => app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['keeper'], CashSubmission::OUTCOME_ACCEPTED, 'It was all there',
    ))->toThrow(RuntimeException::class, 'cash you handed over yourself');
});

test('the person who raised the dispute cannot rule on it either', function () {
    $ctx = disputedHandover();

    // They already made a claim; deciding it too would be ruling on their own
    // account.
    expect(fn () => app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['collector'], CashSubmission::OUTCOME_CHARGED, 'Short',
    ))->toThrow(RuntimeException::class, 'dispute you raised yourself');
});

test('a passer-by cannot settle it', function () {
    $ctx = disputedHandover();

    expect(fn () => app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], User::factory()->create(), CashSubmission::OUTCOME_ACCEPTED, 'Fine',
    ))->toThrow(RuntimeException::class, 'not permitted');
});

test('a handover that is not disputed has nothing to settle', function () {
    $ctx = handoffContext();

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['keeper'], receiver: $ctx['collector'], store: $ctx['store'], amount: 50000,
    );

    expect(fn () => app(ResolveCashSubmissionAction::class)->settle(
        $submission, $ctx['owner'], CashSubmission::OUTCOME_ACCEPTED, 'n/a',
    ))->toThrow(RuntimeException::class, 'Only a disputed handover');
});

test('a dispute cannot be settled twice, and nobody is charged twice', function () {
    $ctx = disputedHandover();

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_CHARGED, 'Short',
    );

    expect(fn () => app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission']->fresh(), $ctx['owner'], CashSubmission::OUTCOME_CHARGED, 'Again',
    ))->toThrow(RuntimeException::class, 'Only a disputed handover');

    expect(AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)->count())->toBe(1);
});

test('a settled dispute stops counting as contested on the settlement', function () {
    $ctx = disputedHandover(claimed: 50000, received: 42000);

    $before = app(App\Services\Cash\StoreReconciliation::class)
        ->forStore($ctx['store'], now()->subMonth(), now()->addDay());

    expect($before['outstanding']['disputed'])->toBe(8000.0)
        ->and($before['outstanding']['true_shortage'])->toBe(8000.0);

    app(ResolveCashSubmissionAction::class)->settle(
        $ctx['submission'], $ctx['owner'], CashSubmission::OUTCOME_CHARGED, 'Short by 8,000',
    );

    $after = app(App\Services\Cash\StoreReconciliation::class)
        ->forStore($ctx['store'], now()->subMonth(), now()->addDay());

    // No longer contested — it has an answer, and the 8,000 now sits on the
    // accountability ledger rather than hanging over the branch.
    expect($after['outstanding']['disputed'])->toBe(0.0)
        ->and($after['cash']['confirmed'])->toBe(42000.0);
});
