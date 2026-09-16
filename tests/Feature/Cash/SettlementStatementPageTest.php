<?php

use App\Actions\Cash\GenerateSettlementStatementAction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

function statementFor(array $ctx)
{
    return app(GenerateSettlementStatementAction::class)->execute(
        generatedBy: $ctx['owner'],
        store:       $ctx['store'],
        from:        now()->subMonth(),
        to:          now()->addDay(),
    );
}

test('the statement page shows the headline figures and who generated it', function () {
    $ctx = handoffContext();
    $statement = statementFor($ctx);

    $this->actingAs($ctx['owner'])
        ->get(route('settlement.show', $statement))
        ->assertOk()
        ->assertSee('Store Settlement Statement')
        ->assertSee($statement->reference)
        ->assertSee($ctx['owner']->name)
        ->assertSee('Unexplained shortage')
        ->assertSee('50,000.00');
});

test('a statement with no stock count says so rather than leaving it blank', function () {
    $ctx = handoffContext();
    $statement = statementFor($ctx);

    // A settlement that has only checked the money should admit it.
    $this->actingAs($ctx['owner'])
        ->get(route('settlement.show', $statement))
        ->assertOk()
        ->assertSee('No physical count was entered');
});

test('the statement downloads as a pdf', function () {
    $ctx = handoffContext();
    $statement = statementFor($ctx);

    $response = $this->actingAs($ctx['owner'])->get(route('settlement.pdf', $statement));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))
        ->toContain($statement->reference . '.pdf');
});

test('a statement cannot be read by somebody from another business', function () {
    $ctx = handoffContext();
    $statement = statementFor($ctx);

    $outsider = User::factory()->create();
    Vendor::create(['user_id' => $outsider->id, 'name' => 'Someone Else Ltd']);

    // It names people and says what they are short of, so the only thing that
    // matters is that none of it comes back. The app turns a 403 into a
    // redirect to sign-in globally, so that is what refusal looks like here.
    $response = $this->actingAs($outsider)->get(route('settlement.show', $statement));

    expect($response->isOk())->toBeFalse();
    $response->assertDontSee($statement->reference)
        ->assertDontSee('Unexplained shortage');
});

test('the printed copy keeps saying what it said when it was printed', function () {
    $ctx = handoffContext();
    $statement = statementFor($ctx);

    cashSale($ctx['vendor'], $ctx['store'], $ctx['keeper']->id, ['total' => 90000]);

    // 140,000 has now been taken, but this copy was printed at 50,000.
    $this->actingAs($ctx['owner'])
        ->get(route('settlement.show', $statement))
        ->assertOk()
        ->assertSee('50,000.00')
        ->assertDontSee('140,000.00');
});
