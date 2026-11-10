<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\Queries\CashVarianceQuery;
use App\Actions\Assistant\Queries\CustomerDebtsQuery;
use App\Actions\Assistant\Queries\DeadStockQuery;
use App\Actions\Assistant\Queries\GlossaryQuery;
use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\QueryResult;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\SupplierOweingsQuery;
use App\Actions\Assistant\SendMessageAction;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\AssistantSetting;
use App\Models\AssistantThread;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;

uses(RefreshDatabase::class);

function setupHonestVendor(int $storeAgeDays = 30): array
{
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Honest Vendor '.uniqid()]);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Honest Store',
        'created_at' => now()->subDays($storeAgeDays),
    ]);
    $store->update(['created_at' => now()->subDays($storeAgeDays)]);
    $user->stores()->syncWithoutDetaching([$store->id]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    AssistantGlobalSetting::current()->update([
        'is_enabled' => true,
        'history_window' => 20,
        'model_name' => 'openai/gpt-4o-mini',
        'max_tokens' => 800,
    ]);

    test()->actingAs($user);

    return [$user, $vendor, $store];
}

/*
|--------------------------------------------------------------------------
| QueryResult Helper Tests
|--------------------------------------------------------------------------
*/

test('QueryResult::withData constructs payload with has_data true and null reason', function () {
    $result = QueryResult::withData(['x' => 1]);

    expect($result)->toBe([
        'x' => 1,
        'has_data' => true,
        'reason' => null,
    ]);
});

test('QueryResult::empty constructs structured failure response with validated reason', function () {
    $result = QueryResult::empty('no_cash_ups_yet');

    expect($result)->toBe([
        'has_data' => false,
        'reason' => 'no_cash_ups_yet',
    ]);
});

test('QueryResult::empty throws InvalidArgumentException on unrecognized reason', function () {
    QueryResult::empty('random_unregistered_reason');
})->throws(InvalidArgumentException::class, 'Invalid query failure reason');

test('QueryResult::REASONS contains unique valid reasons without duplicates', function () {
    $reasons = QueryResult::REASONS;

    expect(count($reasons))->toBe(count(array_unique($reasons)))
        ->and($reasons)->toContain('no_cash_ups_yet')
        ->and($reasons)->toContain('store_too_new')
        ->and($reasons)->toContain('no_procurements')
        ->and($reasons)->toContain('no_payouts')
        ->and($reasons)->toContain('no_commissions')
        ->and($reasons)->toContain('no_debts')
        ->and($reasons)->toContain('no_pickers')
        ->and($reasons)->toContain('not_configured')
        ->and($reasons)->toContain('internal_error')
        ->and($reasons)->toContain('no_sales_yet');
});

/*
|--------------------------------------------------------------------------
| Query Contracts and Structured Refusals
|--------------------------------------------------------------------------
*/

test('SalesSummaryQuery on empty established store returns withData and revenue 0', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);

    $query = new SalesSummaryQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(7), now());

    expect($result['has_data'])->toBeTrue()
        ->and($result['reason'])->toBeNull()
        ->and($result['revenue'])->toBe(0.0)
        ->and($result['orders'])->toBe(0);
});

test('CashVarianceQuery on store with no cash-ups returns empty with no_cash_ups_yet', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);

    $query = new CashVarianceQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(7), now());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('no_cash_ups_yet');
});

test('SupplierOweingsQuery with no procurements returns empty with no_procurements', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);

    $query = new SupplierOweingsQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(7), now());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('no_procurements');
});

test('CustomerDebtsQuery with no debts returns empty with no_debts', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);

    $query = new CustomerDebtsQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(7), now());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('no_debts');
});

test('DeadStockQuery on store < 30 days old returns empty with store_too_new', function () {
    [$user, $vendor, $store] = setupHonestVendor(10);

    $query = new DeadStockQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(30), now());

    expect($result['has_data'])->toBeFalse()
        ->and($result['reason'])->toBe('store_too_new');
});

// Phase 2: low stock used to answer "not set up" unless the model invented a
// threshold. It now uses each product's own low-stock level, as the screens do.
test('LowStockQuery with no threshold option uses each product\'s own low-stock level', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);

    $query = new LowStockQuery;
    $result = $query->execute(['store_id' => $store->id], now()->subDays(7), now());

    expect($result['has_data'])->toBeTrue()
        ->and($result['threshold'])->toBe('each product\'s own low-stock level')
        ->and($result['count'])->toBe(0);
});

test('GlossaryQuery with unknown term returns withData with found false', function () {
    $query = new GlossaryQuery;
    $result = $query->execute([], now(), now(), ['term' => 'completely unknown jargon']);

    expect($result['has_data'])->toBeTrue()
        ->and($result['reason'])->toBeNull()
        ->and($result['found'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| AssistantQueryRun Persistence Tests
|--------------------------------------------------------------------------
*/

test('AssistantQueryRun persists has_data false and failure_reason when query returns refusal', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => "What's my cash variance?",
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: AI calls cash_variance
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_cv_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'cash_variance',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

    // Call 2: AI phrases the refusal based on has_data: false
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $toolMessage = collect($messages)->firstWhere('role', 'tool');
            if (! $toolMessage) {
                return false;
            }
            $payload = json_decode((string) $toolMessage['content'], true);

            return isset($payload['has_data']) && $payload['has_data'] === false && $payload['reason'] === 'no_cash_ups_yet';
        })
        ->andReturn([
            'content' => "You haven't done a cash-up yet. I can still show you sales, stock, or payouts.",
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 30],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toContain("You haven't done a cash-up yet");

    $run = AssistantQueryRun::where('message_id', $reply->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->query_key)->toBe('cash_variance')
        ->and($run->success)->toBeTrue()
        ->and($run->has_data)->toBeFalse()
        ->and($run->failure_reason)->toBe('no_cash_ups_yet');
});

test('AssistantQueryRun persists has_data true and failure_reason null on successful data fetch', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Show me sales summary',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_ss_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'You had ₦0 in sales across 0 orders this week.',
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 25],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    $run = AssistantQueryRun::where('message_id', $reply->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->success)->toBeTrue()
        ->and($run->has_data)->toBeTrue()
        ->and($run->failure_reason)->toBeNull();
});

test('AssistantQueryRun persists success false and has_data true default on query exception', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Query foreign store',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_err_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => 999999]), // unowned store
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => "I couldn't fetch that right now.",
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    $run = AssistantQueryRun::where('message_id', $reply->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->success)->toBeFalse()
        ->and($run->has_data)->toBeTrue()
        ->and($run->error_message)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| AI Phrasing with Mocked AiClient
|--------------------------------------------------------------------------
*/

test('AI phrasing: refusal receives structured has_data false, phrases with offer, and invents no numbers', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'How much cash variance do I have?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_cv_2',
                    'type' => 'function',
                    'function' => [
                        'name' => 'cash_variance',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

    $aiReplyText = "You haven't done a cash-up yet. I can still show you sales, stock, or debts.";

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $toolMsg = collect($messages)->firstWhere('role', 'tool');
            $payload = json_decode((string) $toolMsg['content'], true);

            return $payload['has_data'] === false && $payload['reason'] === 'no_cash_ups_yet';
        })
        ->andReturn([
            'content' => $aiReplyText,
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 30],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toContain('cash-up')
        ->and($reply->content)->toContain('I can still')
        // Enforce that refusal contains no digits
        ->and(preg_match('/\d/', $reply->content))->toBe(0);
});

test('AI phrasing: a tool called without what it needs returns set up explanation', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Find me a product',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [
                [
                    'id' => 'call_ls_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'find_products',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $toolMsg = collect($messages)->firstWhere('role', 'tool');
            $payload = json_decode((string) $toolMsg['content'], true);

            return $payload['has_data'] === false && $payload['reason'] === 'not_configured';
        })
        ->andReturn([
            'content' => "That isn't set up yet. I can still show you sales, stock value, or customer debts.",
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 25],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toMatch('/set up|configure/i');
});

test('AI phrasing: stop with no tool sets no_tool_used and returns general offer', function () {
    [$user, $vendor, $store] = setupHonestVendor(30);
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => "What's the weather like in Lagos?",
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => "I can't check that yet — but I can tell you about sales, stock, cash variance, payouts, debts, or affiliate commissions.",
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 30],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->ai_meta['no_tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_used'])->toBeFalse()
        ->and($reply->content)->toContain("I can't check that yet")
        ->and($reply->content)->toContain('I can tell you about');
});

/*
|--------------------------------------------------------------------------
| Pre-flight Short-circuit Tests
|--------------------------------------------------------------------------
*/

test('Pre-flight short-circuit: brand-new store (<24h old, zero activity) skips AI call and saves static reply', function () {
    // Brand new store (created 2 hours ago, 0 orders, 0 stock, 0 cash-ups)
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Brand New Vendor']);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Brand New Store',
        'created_at' => now()->subHours(2),
    ]);
    $store->update(['created_at' => now()->subHours(2)]);
    $user->stores()->syncWithoutDetaching([$store->id]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldNotReceive('chat');

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, "What's my cash variance?", $store->id);

    expect($result['assistant_message']->content)->toBe('This store has no activity yet. Once sales, stock, or cash-ups start coming in, ask me again.')
        ->and($result['assistant_message']->ai_meta['short_circuit'])->toBeTrue();

    test()->assertDatabaseCount('assistant_query_runs', 0);
});

test('Pre-flight short-circuit: established store (>24h old) with zero activity does NOT short-circuit and goes through normal AI', function () {
    // 25 hours old store
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Established Quiet Vendor']);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Established Store',
        'created_at' => now()->subHours(25),
    ]);
    $store->update(['created_at' => now()->subHours(25)]);
    $user->stores()->syncWithoutDetaching([$store->id]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'You have no activity today.',
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 10],
        ]);

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'How are sales today?', $store->id);

    expect($result['assistant_message']->content)->toBe('You have no activity today.')
        ->and($result['assistant_message']->ai_meta['short_circuit'] ?? false)->toBeFalse();
});

test('Pre-flight short-circuit: brand-new store with prior cash-up does NOT short-circuit', function () {
    // Created 1 hour ago, but has a cash-up (posSession)
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Active New Vendor']);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Active New Store',
        'created_at' => now()->subHours(1),
    ]);
    $store->update(['created_at' => now()->subHours(1)]);
    $user->stores()->syncWithoutDetaching([$store->id]);

    PosSession::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $user->id,
        'business_date' => now()->toDateString(),
        'status' => 'closed',
        'opened_at' => now()->subMinutes(30),
        'opening_float' => 500.00,
    ]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Sales are doing well!',
            'finish_reason' => 'stop',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 10],
        ]);

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'How are sales today?', $store->id);

    expect($result['assistant_message']->content)->toBe('Sales are doing well!')
        ->and($result['assistant_message']->ai_meta['short_circuit'] ?? false)->toBeFalse();
});
