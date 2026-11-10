<?php

declare(strict_types=1);

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\SendMessageAction;
use App\Enums\Assistant\AnswerStatus;
use App\Exceptions\Assistant\AiProviderException;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantUnansweredQuestion;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\CostCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Phase 1.3: every question leaves a record of how it went (status), how long
 * it took, and what it cost, cache tokens included; and the ones the assistant
 * should have answered and did not are kept for the admin to review.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor, store: Store} */
function visibilityVendor(): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0, 'daily_vendor_cap' => 100]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Visibility Phones']);
    $store = $vendor->defaultStore;
    $store->forceFill(['created_at' => now()->subDays(60)])->save();

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $store->fresh()];
}

/** A scripted model: each call returns the next response, or throws it. */
function visibilityModel(array $responses): void
{
    $client = Mockery::mock(AiClient::class);
    $expectation = $client->shouldReceive('chat')->times(count($responses));
    $expectation->andReturnUsing(function () use (&$responses) {
        $next = array_shift($responses);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    });

    app()->instance(AiClient::class, $client);
}

function visibilityReply(?string $content, array $toolCalls = [], array $usage = []): array
{
    return [
        'content' => $content,
        'tool_calls' => $toolCalls,
        'finish_reason' => $toolCalls === [] ? 'stop' : 'tool_calls',
        'usage' => [...['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050], ...$usage],
    ];
}

function visibilityTool(string $name, array $args = []): array
{
    return [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]];
}

function visibilityAsk(array $ctx, string $question): AssistantMessage
{
    test()->actingAs($ctx['owner']);

    return app(SendMessageAction::class)->execute($ctx['owner'], null, $question, $ctx['store']->id, $ctx['vendor'])['assistant_message']->fresh();
}

test('an answered question records status, latency, rounds, model time and cache tokens', function () {
    $ctx = visibilityVendor();
    PosSale::create([
        'reference' => 'POS-'.Str::random(8), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'cashier_id' => $ctx['owner']->id, 'subtotal' => 5000, 'discount_amount' => 0, 'vat_amount' => 0,
        'total' => 5000, 'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => now()->subHour(),
    ]);

    visibilityModel([
        visibilityReply(null, visibilityTool('sales_summary'), ['cache_read_tokens' => 0, 'cache_write_tokens' => 900]),
        visibilityReply('You sold ₦5,000.', [], ['cache_read_tokens' => 900, 'cache_write_tokens' => 0]),
    ]);

    $answer = visibilityAsk($ctx, 'How much did we sell today?');

    expect($answer->status)->toBe(AnswerStatus::Answered)
        ->and($answer->latency_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($answer->ai_meta['rounds'])->toBe(2)
        ->and($answer->ai_meta['model_ms'])->toBeInt()
        ->and($answer->ai_meta['tokens_in'])->toBe(2000)
        ->and($answer->ai_meta['cache_read_tokens'])->toBe(900)
        ->and($answer->ai_meta['cache_write_tokens'])->toBe(900)
        ->and($answer->ai_meta['channel'])->toBe('panel')
        ->and(AssistantUnansweredQuestion::count())->toBe(0);
});

test('cost is what OpenRouter billed when it says, and the rate table when it does not', function () {
    $ctx = visibilityVendor();

    visibilityModel([visibilityReply('Hello.', [], ['cost_usd' => 0.0123])]);
    $billed = visibilityAsk($ctx, 'Hi');

    visibilityModel([visibilityReply('Hello again.')]);
    $estimated = visibilityAsk($ctx, 'Hi again');

    expect($billed->ai_meta['cost_micros'])->toBe(12300)
        ->and($billed->ai_meta['cost_source'])->toBe('provider')
        ->and($estimated->ai_meta['cost_micros'])->toBe(CostCalculator::costMicros('openai/gpt-4o-mini', 1000, 50))
        ->and($estimated->ai_meta['cost_source'])->toBe('rate_table');
});

test('"I can\'t check that yet" is logged as unanswered, with the question and the business', function () {
    $ctx = visibilityVendor();
    visibilityModel([visibilityReply("I can't check that yet. I can tell you about sales or stock.")]);

    $answer = visibilityAsk($ctx, 'How many phones did we transfer to Ikeja?');
    $logged = AssistantUnansweredQuestion::sole();

    expect($answer->status)->toBe(AnswerStatus::CannotAnswer)
        ->and($logged->reason)->toBe(AnswerStatus::CannotAnswer)
        ->and($logged->question)->toBe('How many phones did we transfer to Ikeja?')
        ->and($logged->vendor_id)->toBe($ctx['vendor']->id)
        ->and($logged->user_id)->toBe($ctx['owner']->id)
        ->and($logged->answer_message_id)->toBe($answer->id)
        ->and($logged->reviewed_at)->toBeNull();
});

test('a tool that finds nothing is an honest answer, not an unanswered one', function () {
    $ctx = visibilityVendor();
    visibilityModel([
        visibilityReply(null, visibilityTool('customer_debts')),
        visibilityReply('No customers owe you anything.'),
    ]);

    $answer = visibilityAsk($ctx, 'Who owes us?');

    expect($answer->status)->toBe(AnswerStatus::NoData)
        ->and(AssistantUnansweredQuestion::count())->toBe(0);
});

test('when every tool call fails, it is logged as a tool error naming the tool', function () {
    $ctx = visibilityVendor();
    visibilityModel([
        visibilityReply(null, visibilityTool('no_such_tool')),
        visibilityReply("I couldn't fetch that."),
    ]);

    $answer = visibilityAsk($ctx, 'Anything');

    expect($answer->status)->toBe(AnswerStatus::ToolError)
        ->and(AssistantUnansweredQuestion::sole()->detail)->toContain('failed tools: no_such_tool');
});

test('a provider failure is logged with its category, and the user gets the friendly reply', function () {
    $ctx = visibilityVendor();
    visibilityModel([new AiProviderException('HTTP 402', 'credit_exhausted', 402)]);

    $answer = visibilityAsk($ctx, 'How much did we sell?');
    $logged = AssistantUnansweredQuestion::sole();

    expect($answer->status)->toBe(AnswerStatus::ProviderError)
        ->and($answer->content)->toBe('The assistant is out of credit right now. Please contact your administrator.')
        ->and($logged->reason)->toBe(AnswerStatus::ProviderError)
        ->and($logged->detail)->toContain('category: credit_exhausted');
});

test('figures fetched but the reply failed is logged as a phrasing failure', function () {
    $ctx = visibilityVendor();
    visibilityModel([
        visibilityReply(null, visibilityTool('sales_summary')),
        new AiProviderException('HTTP 500', 'provider_error', 500),
    ]);

    $answer = visibilityAsk($ctx, 'How much did we sell?');

    expect($answer->status)->toBe(AnswerStatus::PhrasingFailed)
        ->and($answer->content)->toBe('I fetched your data but had trouble phrasing it — try again.')
        ->and(AssistantUnansweredQuestion::sole()->reason)->toBe(AnswerStatus::PhrasingFailed);
});

test('a refusal for lack of access is its own status and is not logged as unanswered', function () {
    expect(AskAssistantAction::statusFor(null, false, true, [
        ['success' => true, 'has_data' => false, 'failure_reason' => 'no_access'],
    ], "You don't have access to that."))->toBe(AnswerStatus::NoAccess)
        ->and(AnswerStatus::NoAccess->isUnanswered())->toBeFalse()
        ->and(AnswerStatus::NoData->isUnanswered())->toBeFalse()
        ->and(AnswerStatus::CapReached->isUnanswered())->toBeFalse();
});

test('a cap refusal is recorded as cap_reached, and is neither unanswered nor sent to the model', function () {
    $ctx = visibilityVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 1]);

    visibilityModel([visibilityReply('Hello.')]);
    visibilityAsk($ctx, 'Hi');
    $refused = visibilityAsk($ctx, 'Hi again');

    expect($refused->status)->toBe(AnswerStatus::CapReached)
        ->and($refused->latency_ms)->toBeInt()
        ->and(AssistantUnansweredQuestion::count())->toBe(0);
});

test('the AI client reads OpenRouter\'s cache and cost figures and asks for them', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 1200, 'completion_tokens' => 30, 'total_tokens' => 1230, 'cost' => 0.0042,
            'prompt_tokens_details' => ['cached_tokens' => 1000, 'cache_write_tokens' => 0],
        ],
    ])]);

    $usage = (new AiClient)->chat([['role' => 'user', 'content' => 'hi']], [], 'anthropic/claude-sonnet', 100)['usage'];

    expect($usage['cache_read_tokens'])->toBe(1000)
        ->and($usage['cache_write_tokens'])->toBe(0)
        ->and($usage['cost_usd'])->toBe(0.0042);

    Http::assertSent(fn ($request) => ($request->data()['usage']['include'] ?? null) === true);
});

test('without cache or cost in the response, they read as zero and unknown', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
    ])]);

    $usage = (new AiClient)->chat([['role' => 'user', 'content' => 'hi']], [], 'openai/gpt-4o-mini', 100)['usage'];

    expect($usage['cache_read_tokens'])->toBe(0)
        ->and($usage['cache_write_tokens'])->toBe(0)
        ->and($usage['cost_usd'])->toBeNull();
});
