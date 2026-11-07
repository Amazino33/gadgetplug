<?php

declare(strict_types=1);

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\SendMessageAction;
use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\AssistantSetting;
use App\Models\AssistantThread;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createVendorContext(): array
{
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store']);
    $store->update(['created_at' => now()->subDays(60)]);
    $user->stores()->attach($store->id);

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

    return [$user, $vendor, $store];
}

test('happy path with tool call runs query, creates query run row, and saves assistant reply with ai_meta', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'What were my sales this week?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: AI decides to use tool sales_summary
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_sales_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 25, 'total_tokens' => 145],
        ]);

    // Call 2: AI answers with formatted summary using tool result
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Your sales for the period were ₦0.00 across 0 orders.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 18, 'total_tokens' => 198],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->role)->toBe('assistant')
        ->and($reply->content)->toContain('₦0.00')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBe('sales_summary')
        ->and($reply->ai_meta['no_tool_used'])->toBeFalse()
        ->and($reply->ai_meta['tokens_in'])->toBe(300)
        ->and($reply->ai_meta['tokens_out'])->toBe(43);

    $this->assertDatabaseHas('assistant_query_runs', [
        'message_id' => $reply->id,
        'query_key' => 'sales_summary',
        'success' => true,
    ]);
});

test('happy path without tool saves assistant message with no_tool_used and no query run row', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Hello there!',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Hello! How can I assist you with your store today?',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 14, 'total_tokens' => 94],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe('Hello! How can I assist you with your store today?')
        ->and($reply->ai_meta['tool_used'])->toBeFalse()
        ->and($reply->ai_meta['no_tool_used'])->toBeTrue();

    $this->assertDatabaseCount('assistant_query_runs', 0);
});

test('tool call exception creates failed query run row and sends error to second AI call', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Check foreign store',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: requests unauthorized store 99999
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_err_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => 99999]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    // Call 2 receives the error context and phrases polite failure
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'error');
        })
        ->andReturn([
            'content' => "I couldn't fetch that right now.",
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 10, 'total_tokens' => 160],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe("I couldn't fetch that right now.");

    $run = AssistantQueryRun::first();
    expect($run)->not->toBeNull()
        ->and($run->success)->toBeFalse()
        ->and($run->error_message)->toContain('Unauthorized access to store [99999]');
});

test('AI provider failure in SendMessageAction saves fallback message and logs error', function () {
    [$user, $vendor, $store] = createVendorContext();
    $this->actingAs($user);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andThrow(new AiProviderException('OpenRouter rate limited', 'network_timeout'));

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'Can you help check my stock?');

    expect($result['assistant_message']->content)->toBe('The assistant took too long to respond.')
        ->and($result['assistant_message']->ai_meta['error'])->toContain('OpenRouter rate limited');

    $this->assertDatabaseCount('assistant_query_runs', 0);
});

test('AI returning malformed null content defaults to polite fallback', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);
    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Tell me something',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 0, 'total_tokens' => 50],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe('Sorry, I had trouble answering that.');
});

test('history window is respected and passed to AI client', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);
    $this->actingAs($user);

    for ($i = 1; $i <= 30; $i++) {
        AssistantMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => "Historic message {$i}",
        ]);
    }

    $newUserMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'New message',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    // Window = 20 -> total messages sent to chat: 1 system + 20 history + 1 new user message = 22 messages
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            return count($messages) === 22
                && $messages[0]['role'] === 'system'
                && $messages[count($messages) - 1]['content'] === 'New message';
        })
        ->andReturn([
            'content' => 'Window verified.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 5, 'total_tokens' => 505],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $newUserMsg, $store);

    expect($reply->content)->toBe('Window verified.');
});

test('store ownership re-check throws when active store is not owned by vendor', function () {
    [$userA, $vendorA, $storeA] = createVendorContext();

    $userB = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $userB->id, 'name' => 'Foreign Vendor']);
    $foreignStore = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Foreign Store']);

    $thread = AssistantThread::create(['user_id' => $userA->id]);
    $this->actingAs($userA);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $userA->id,
        'role' => 'user',
        'content' => 'Read foreign store',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);

    expect(fn () => $action->execute($thread, $userMsg, $foreignStore))
        ->toThrow(UnauthorizedStoreAccess::class);
});

test('disabled mid-thread blocks send before any AI call', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);
    $this->actingAs($user);

    // Disable mid-session
    $user->assistantSetting->update(['vendor_disabled' => true]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Should be blocked',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldNotReceive('chat');

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);

    expect(fn () => $action->execute($thread, $userMsg, $store))
        ->toThrow(AuthorizationException::class);
});

test('every tool in toolSchema exists in registry keys', function () {
    $registry = new AssistantQueryRegistry;
    $builder = new PromptBuilder;

    $tools = $builder->toolSchema($registry);
    $registryKeys = $registry->keys();

    expect($tools)->toHaveCount(count($registryKeys));

    foreach ($tools as $tool) {
        $name = $tool['function']['name'];
        expect(in_array($name, $registryKeys, true))
            ->toBeTrue("Tool [{$name}] was in toolSchema but not in registry keys.");
    }
});

test('second AI call failure preserves successful query run row and returns phrasing fallback', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'last_message_at' => now()->subHour(),
    ]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Show me sales summary',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1 succeeds and triggers tool call
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_timeout_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    // Call 2 times out
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andThrow(new AiProviderException('OpenRouter timeout on second call'));

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe('I fetched your data but had trouble phrasing it — try again.')
        ->and($reply->role)->toBe('assistant');

    // Assert query run row was preserved despite AI call #2 failure
    $run = AssistantQueryRun::first();
    expect($run)->not->toBeNull()
        ->and($run->success)->toBeTrue()
        ->and($run->query_key)->toBe('sales_summary')
        ->and($run->result_json)->toBeArray()
        ->and($run->message_id)->toBe($reply->id);

    // Assert thread last_message_at was touched
    $thread->refresh();
    expect($thread->last_message_at->greaterThan(now()->subMinute()))->toBeTrue();
});

test('multi-tool execution runs both queries, creates two query runs, and passes both results to AI Call #2', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'how much was sold yesterday and today?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: returns two tool calls (sales_summary for yesterday, sales_summary for today)
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_yesterday_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-03', 'date_to' => '2026-11-03']),
                    ],
                ],
                [
                    'id' => 'call_today_2',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-04', 'date_to' => '2026-11-04']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 40, 'total_tokens' => 190],
        ]);

    // Call 2: receives messages with tool responses for both executed tool calls
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($secondMessages, $tools, $modelName, $maxTokens) {
            $toolMessages = array_values(array_filter($secondMessages, fn ($m) => ($m['role'] ?? '') === 'tool'));

            if (count($toolMessages) !== 2) {
                return false;
            }

            if ($toolMessages[0]['tool_call_id'] !== 'call_yesterday_1') {
                return false;
            }

            if ($toolMessages[1]['tool_call_id'] !== 'call_today_2') {
                return false;
            }

            $payload1 = json_decode((string) $toolMessages[0]['content'], true);
            $payload2 = json_decode((string) $toolMessages[1]['content'], true);

            return ($payload1['has_data'] ?? false) === true
                && ($payload2['has_data'] ?? false) === true
                && ! isset($payload1['error'])
                && ! isset($payload2['error']);
        })
        ->andReturn([
            'content' => 'Yesterday you had sales of ₦0.00 and today ₦0.00.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 220, 'completion_tokens' => 25, 'total_tokens' => 245],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->role)->toBe('assistant')
        ->and($reply->content)->toBe('Yesterday you had sales of ₦0.00 and today ₦0.00.')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBe('sales_summary')
        ->and(count($reply->ai_meta['extra_tool_calls']))->toBe(1)
        ->and($reply->ai_meta['extra_tool_calls'][0]['id'])->toBe('call_today_2');

    expect(AssistantQueryRun::where('message_id', $reply->id)->count())->toBe(2);
});

test('multi-tool execution limit executes maximum 3 tool calls and sends synthetic limit error for remaining calls', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'show sales for last 5 days',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: returns 5 tool calls
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-01', 'date_to' => '2026-11-01']),
                    ],
                ],
                [
                    'id' => 'call_2',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-02', 'date_to' => '2026-11-02']),
                    ],
                ],
                [
                    'id' => 'call_3',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-03', 'date_to' => '2026-11-03']),
                    ],
                ],
                [
                    'id' => 'call_4',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-04', 'date_to' => '2026-11-04']),
                    ],
                ],
                [
                    'id' => 'call_5',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-05', 'date_to' => '2026-11-05']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 60, 'total_tokens' => 260],
        ]);

    // Call 2: receives all 5 tool turns; calls 1-3 have data, calls 4-5 have limit reached error
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($secondMessages, $tools, $modelName, $maxTokens) {
            $toolMessages = array_values(array_filter($secondMessages, fn ($m) => ($m['role'] ?? '') === 'tool'));

            if (count($toolMessages) !== 5) {
                return false;
            }

            for ($i = 0; $i < 3; $i++) {
                $payload = json_decode((string) $toolMessages[$i]['content'], true);
                if (($payload['has_data'] ?? false) !== true) {
                    return false;
                }
            }

            for ($i = 3; $i < 5; $i++) {
                $payload = json_decode((string) $toolMessages[$i]['content'], true);
                if (($payload['error'] ?? null) !== 'Execution limit reached.') {
                    return false;
                }
            }

            return true;
        })
        ->andReturn([
            'content' => 'Here are your sales for the first 3 days.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20, 'total_tokens' => 320],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->role)->toBe('assistant')
        ->and($reply->content)->toBe('Here are your sales for the first 3 days.')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and(count($reply->ai_meta['extra_tool_calls']))->toBe(4);

    expect(AssistantQueryRun::where('message_id', $reply->id)->count())->toBe(3);
});

test('sales_by_day returns exact kobo integer 44220000 for 442200 naira sale on specific date', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    PosSale::create([
        'reference' => 'POS-SALE-442200',
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $user->id,
        'subtotal' => 442200.00,
        'discount_amount' => 0,
        'total' => 442200.00,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => Carbon::parse('2026-11-04 14:30:00'),
    ]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'how much was sold on 2026-11-04?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_day_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_by_day',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => '2026-11-04', 'date_to' => '2026-11-04']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    $capturedToolPayload = null;
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($secondMessages) use (&$capturedToolPayload) {
            $toolMsg = collect($secondMessages)->firstWhere('role', 'tool');
            if ($toolMsg) {
                $capturedToolPayload = json_decode((string) $toolMsg['content'], true);
            }

            return true;
        })
        ->andReturn([
            'content' => 'On 2026-11-04 you made ₦442,200.00 across 1 order.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20, 'total_tokens' => 170],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($capturedToolPayload)->not->toBeNull()
        ->and($capturedToolPayload['has_data'])->toBeTrue()
        ->and($capturedToolPayload['days'])->toHaveCount(1)
        ->and($capturedToolPayload['days'][0]['revenue'])->toBe(44220000)
        ->and($reply->content)->toContain('₦442,200.00');
});

test('asking what is my profit calls profit tool and returns profit data', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'What is my profit?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $systemMsg = collect($messages)->firstWhere('role', 'system');

            return str_contains($systemMsg['content'] ?? '', 'You now have a profit tool. Use it when the user asks about margins or profit. If no data exists, state that.');
        })
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_profit_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'profit',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 15, 'total_tokens' => 95],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Your net profit is ₦5,000.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20, 'total_tokens' => 140],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe('Your net profit is ₦5,000.')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBe('profit');
});

test('asking for customer history passes customer_name option to customer_history query', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $customer = PosCustomer::create([
        'vendor_id' => $vendor->id,
        'name' => 'Chidi Okonkwo',
        'phone' => '08012345678',
    ]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'What did Chidi buy?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $systemMsg = collect($messages)->firstWhere('role', 'system');

            return str_contains($systemMsg['content'] ?? '', 'customer_history');
        })
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_cust_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'customer_history',
                        'arguments' => json_encode(['customer_name' => 'Chidi Okonkwo']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 15, 'total_tokens' => 95],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Chidi Okonkwo bought items.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20, 'total_tokens' => 140],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toBe('Chidi Okonkwo bought items.')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBe('customer_history');

    $run = AssistantQueryRun::where('message_id', $reply->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->query_key)->toBe('customer_history')
        ->and($run->success)->toBeTrue();
});

test('nested array kobo values are formatted as Naira in final response', function () {
    [$user, $vendor, $store] = createVendorContext();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Were there any cashier shortages today?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // AI calls cashier_shortages
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $systemMsg = collect($messages)->firstWhere('role', 'system');

            return str_contains($systemMsg['content'] ?? '', 'This includes nested fields inside arrays (like variance or amount). You must divide ALL kobo values by 100 to get Naira before showing the user.');
        })
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_shortage_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'cashier_shortages',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 15, 'total_tokens' => 95],
        ]);

    // In AI call #2, tool result has nested variance: 50000 (kobo), AI formats it to ₦500
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);
            if ($last['role'] !== 'tool') {
                return false;
            }
            $payload = json_decode($last['content'], true);

            return isset($payload['shortages']);
        })
        ->andReturn([
            'content' => 'Cashier Chidi had a variance shortage of ₦500 today.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20, 'total_tokens' => 140],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toContain('₦500')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBe('cashier_shortages');
});

test('follow-up asking for debtor name calls customer_debts with exact same date range and returns the name', function () {
    [$user, $vendor, $store] = createVendorContext();
    $this->actingAs($user);

    $customer = PosCustomer::create([
        'vendor_id' => $vendor->id,
        'name' => 'Emeka Eze',
    ]);

    PosCustomerLedgerEntry::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'pos_customer_id' => $customer->id,
        'direction' => PosCustomerLedgerEntry::DIRECTION_CHARGE,
        'amount' => 45000.00,
        'occurred_at' => now()->subDays(10)->toDateString(),
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($action);

    $tenDaysAgoStr = now()->subDays(10)->toDateString();
    $todayStr = now()->toDateString();

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_debt_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'customer_debts',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => $tenDaysAgoStr, 'date_to' => $todayStr]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'You have 1 customer who owes ₦45,000.00.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20, 'total_tokens' => 170],
        ]);

    $turn1 = $sendAction->execute($user, null, 'how much debt do I have?', $store->id);
    $thread = $turn1['thread'];

    $turn2CalledArgs = null;
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_debt_2',
                    'type' => 'function',
                    'function' => [
                        'name' => 'customer_debts',
                        'arguments' => json_encode(['store_id' => $store->id, 'date_from' => $tenDaysAgoStr, 'date_to' => $todayStr]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20, 'total_tokens' => 140],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($secondMessages) use (&$turn2CalledArgs) {
            $toolMsg = collect($secondMessages)->firstWhere('role', 'tool');
            if ($toolMsg) {
                $turn2CalledArgs = json_decode((string) $toolMsg['content'], true);
            }

            return true;
        })
        ->andReturn([
            'content' => 'The debtor is Emeka Eze who owes ₦45,000.00.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 170, 'completion_tokens' => 20, 'total_tokens' => 190],
        ]);

    $turn2 = $sendAction->execute($user, $thread, 'what is the name?', $store->id);

    expect($turn2['assistant_message']->content)->toContain('Emeka Eze')
        ->and($turn2CalledArgs)->not->toBeNull()
        ->and($turn2CalledArgs['debtors'][0]['name'])->toBe('Emeka Eze');

    $runs = AssistantQueryRun::whereIn('message_id', [$turn1['assistant_message']->id, $turn2['assistant_message']->id])->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->date_from->toDateString())->toBe($runs[1]->date_from->toDateString())
        ->and($runs[0]->date_to->toDateString())->toBe($runs[1]->date_to->toDateString());
});
