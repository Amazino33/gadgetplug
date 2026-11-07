<?php

use App\Models\Assistant\AssistantConversation;
use App\Models\Assistant\AssistantQuestion;
use App\Models\Assistant\AssistantToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Assistant\FakeLanguageModel;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

test('it answers through the assistant service and prints the answer, tools and token usage', function () {
    $ctx = assistantVendor();
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 15000, 2);

    assistantFakeModel([
        FakeLanguageModel::tools('sales_today', [], in: 1200, out: 40),
        FakeLanguageModel::text('₦30,000 today, whole business.', in: 1500, out: 25),
    ]);

    $this->artisan('assistant:ask', ['question' => 'how much did we sell today?', '--user' => $ctx['owner']->id])
        ->expectsOutputToContain('₦30,000 today, whole business.')
        ->expectsOutputToContain('Tools called: sales_today')
        ->expectsOutputToContain('input 2,700 (cache read 0, cache write 0) · output 65')
        ->expectsOutputToContain('sales_today {} → ok')
        ->assertSuccessful();

    // Logged like any other question, labelled with its channel.
    $q = AssistantQuestion::sole();
    expect($q->channel)->toBe('cli')
        ->and($q->status)->toBe('answered')
        ->and($q->counts_toward_cap)->toBeTrue()
        ->and(AssistantToolCall::sole()->tool)->toBe('sales_today');
});

test('it accepts an email and picks the user\'s only vendor', function () {
    $ctx = assistantVendor();
    assistantFakeModel([FakeLanguageModel::text('Hello.')]);

    $this->artisan('assistant:ask', ['question' => 'hi', '--user' => $ctx['owner']->email])
        ->expectsOutputToContain('at '.$ctx['vendor']->name)
        ->assertSuccessful();
});

test('it refuses to guess the vendor for a user who belongs to several', function () {
    $ctx = assistantVendor();
    $other = assistantVendor('Other Shop', 'Other Branch');
    $other['vendor']->users()->attach($ctx['owner']->id);

    $fake = assistantFakeModel([]);

    $this->artisan('assistant:ask', ['question' => 'hi', '--user' => $ctx['owner']->id])
        ->expectsOutputToContain('several vendors')
        ->assertFailed();

    expect($fake->requests)->toBe([]);
});

test('--vendor must be one the user belongs to', function () {
    $ctx = assistantVendor();
    $other = assistantVendor('Other Shop', 'Other Branch');

    assistantFakeModel([]);

    $this->artisan('assistant:ask', ['question' => 'hi', '--user' => $ctx['owner']->id, '--vendor' => $other['vendor']->slug])
        ->expectsOutputToContain('is not a member of Other Shop')
        ->assertFailed();

    expect(AssistantQuestion::count())->toBe(0);
});

test('--user is required', function () {
    $this->artisan('assistant:ask', ['question' => 'hi'])
        ->expectsOutputToContain('Pass --user')
        ->assertFailed();
});

test('the same guards apply: a cashier gets their own sales, and the vendor cap holds', function () {
    $ctx = assistantVendor();
    $cashier = assistantStaff($ctx['vendor'], 'storekeeper', [$ctx['main']]);
    assistantSale($ctx['vendor'], $ctx['main'], $ctx['product'], $ctx['owner'], 90000, 1);
    $ctx['vendor']->update(['assistant_daily_cap' => 1]);

    $fake = assistantFakeModel([FakeLanguageModel::tools('sales_today'), FakeLanguageModel::text('₦0 of your own sales today.')]);

    $this->artisan('assistant:ask', ['question' => 'shop sales today?', '--user' => $cashier->id])->assertSuccessful();

    expect($fake->toolResultsIn(1)[0]['scope'])->toContain('Your own sales only')
        ->and(json_encode($fake->toolResultsIn(1)))->not->toContain('90,000');

    $this->artisan('assistant:ask', ['question' => 'again?', '--user' => $cashier->id])
        ->expectsOutputToContain("used all 1 of today's assistant questions")
        ->expectsOutputToContain('Status:       capped')
        ->assertFailed();

    expect($fake->requests)->toHaveCount(2);
});

test('--conversation continues the user\'s own thread and refuses anyone else\'s', function () {
    $ctx = assistantVendor();
    $other = assistantVendor('Other Shop', 'Other Branch');
    $theirs = AssistantConversation::create(['vendor_id' => $other['vendor']->id, 'user_id' => $other['owner']->id]);

    $fake = assistantFakeModel([FakeLanguageModel::text('First.'), FakeLanguageModel::text('Second.')]);

    $this->artisan('assistant:ask', ['question' => 'one', '--user' => $ctx['owner']->id])->assertSuccessful();
    $mine = AssistantConversation::where('user_id', $ctx['owner']->id)->sole();

    $this->artisan('assistant:ask', ['question' => 'two', '--user' => $ctx['owner']->id, '--conversation' => $mine->id])
        ->expectsOutputToContain('conversation #'.$mine->id)
        ->assertSuccessful();

    expect($fake->requests[1]['messages'][0])->toBe(['role' => 'user', 'content' => 'one']);

    $this->artisan('assistant:ask', ['question' => 'three', '--user' => $ctx['owner']->id, '--conversation' => $theirs->id])
        ->expectsOutputToContain('does not belong to')
        ->assertFailed();
});
