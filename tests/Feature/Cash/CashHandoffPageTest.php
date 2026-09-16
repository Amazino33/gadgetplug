<?php

use App\Models\CashSubmission;
use App\Models\User;
use App\Services\Cash\CashHandoffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('scanning a code shows the amount being claimed', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['collector'])
        ->get(route('cash.handoff', $token))
        ->assertOk()
        ->assertSee('50,000.00')
        ->assertSee($ctx['keeper']->name)
        ->assertSee($submission->reference);
});

test('a scanned code cannot be answered by someone not signed in', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    // An anonymous confirmation would defeat the entire arrangement.
    $this->get(route('cash.handoff', $token))->assertRedirect();
});

test('confirming from the page settles the handover', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['collector'])
        ->post(route('cash.handoff.confirm', $token))
        ->assertOk()
        ->assertSee('Handover confirmed');

    expect($submission->fresh()->status)->toBe(CashSubmission::STATUS_CONFIRMED)
        ->and($submission->fresh()->received_by)->toBe($ctx['collector']->id);
});

test('the submitter cannot confirm their own handover from the page', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['keeper'])
        ->post(route('cash.handoff.confirm', $token))
        ->assertRedirect();

    expect($submission->fresh()->status)->toBe(CashSubmission::STATUS_PENDING);
});

test('a dispute from the page keeps both figures', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['collector'])
        ->post(route('cash.handoff.dispute', $token), [
            'note'            => 'Envelope was light',
            'disputed_amount' => 45000,
        ])
        ->assertOk()
        ->assertSee('Dispute recorded');

    $fresh = $submission->fresh();

    expect($fresh->status)->toBe(CashSubmission::STATUS_DISPUTED)
        ->and((float) $fresh->amount)->toBe(50000.00)
        ->and((float) $fresh->disputed_amount)->toBe(45000.00);
});

test('a dispute has to say what happened', function () {
    $ctx = handoffContext();
    [$submission, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['collector'])
        ->post(route('cash.handoff.dispute', $token), ['disputed_amount' => 45000])
        ->assertSessionHasErrors('note');

    expect($submission->fresh()->status)->toBe(CashSubmission::STATUS_PENDING);
});

test('a spent code shows as no longer valid rather than an error', function () {
    $ctx = handoffContext();
    [, $token] = issueHandoff($ctx);

    $this->actingAs($ctx['collector'])->post(route('cash.handoff.confirm', $token));

    $this->actingAs($ctx['collector'])
        ->get(route('cash.handoff', $token))
        ->assertOk()
        ->assertSee('no longer valid');
});
