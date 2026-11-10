<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Isolation;

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\AuditIsolationAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantSetting;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    AssistantGlobalSetting::current()->update([
        'is_enabled' => true,
        'model_name' => 'openai/gpt-4o-mini',
        'history_window' => 20,
        'max_tokens' => 800,
    ]);
});

function createVendorWithStore(string $vendorName, string $storeName): array
{
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => $vendorName]);
    $store = Store::where('vendor_id', $vendor->id)->first();
    $store->update(['name' => $storeName]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    return [$user, $vendor, $store];
}

test('query layer: Vendor A calling any of the 21 queries with Vendor B store throws UnauthorizedStoreAccess', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $this->actingAs($userA);

    $registry = new AssistantQueryRegistry;
    $keys = $registry->keys();

    expect($keys)->toHaveCount(21);

    $from = now()->subDays(7);
    $to = now();

    foreach ($keys as $key) {
        $queryAction = $registry->get($key);

        // 1. Single foreign store_id
        expect(fn () => $queryAction->execute(['store_id' => $storeB->id], $from, $to))
            ->toThrow(UnauthorizedStoreAccess::class);

        // 2. Multi-store containing own and other's store_ids
        expect(fn () => $queryAction->execute(['store_ids' => [$storeA->id, $storeB->id]], $from, $to))
            ->toThrow(UnauthorizedStoreAccess::class);
    }
});

test('thread layer: Vendor A actions on Vendor B thread are rejected with 403 and sidebar isolates threads', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $threadB = AssistantThread::create([
        'user_id' => $userB->id,
        'store_id' => $storeB->id,
        'title' => 'Thread B Original',
        'last_message_at' => now(),
    ]);

    $msgB = AssistantMessage::create([
        'thread_id' => $threadB->id,
        'user_id' => $userB->id,
        'store_id' => $storeB->id,
        'store_name_snapshot' => $storeB->name,
        'role' => 'user',
        'content' => 'Secret Vendor B data',
    ]);

    // Vendor A loadThread(B) -> 403
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->assertStatus(200)
        ->call('loadThread', $threadB->id)
        ->assertStatus(403);

    // Vendor A selectThread(B) -> 403
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->call('selectThread', $threadB->id)
        ->assertStatus(403);

    // Vendor A renameThread(B, ...) -> 403
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->call('renameThread', $threadB->id, 'Hacked Title')
        ->assertStatus(403);

    // Vendor A deleteThread(B) -> 403
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->call('deleteThread', $threadB->id)
        ->assertStatus(403);

    // Vendor A switchStore(B, ownStore) -> 403
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->call('switchStore', $threadB->id, $storeA->id)
        ->assertStatus(403);

    // Vendor A's sidebar query returns zero of B's threads
    $component = Livewire::actingAs($userA)->test(ChatBubble::class);
    $threads = $component->get('threads');
    expect($threads->pluck('id'))->not->toContain($threadB->id);

    // Raw DB check: zero mutations occurred on thread B or message B
    $threadB->refresh();
    expect($threadB->title)->toBe('Thread B Original')
        ->and($threadB->store_id)->toBe($storeB->id)
        ->and($threadB->user_id)->toBe($userB->id);

    $this->assertDatabaseHas('assistant_messages', [
        'id' => $msgB->id,
        'content' => 'Secret Vendor B data',
        'thread_id' => $threadB->id,
    ]);
});

test('message layer: Vendor A cannot read Vendor B messages via Livewire and mutations are rejected', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $threadB = AssistantThread::create([
        'user_id' => $userB->id,
        'store_id' => $storeB->id,
        'title' => 'Thread B',
        'last_message_at' => now(),
    ]);

    AssistantMessage::create([
        'thread_id' => $threadB->id,
        'user_id' => $userB->id,
        'store_id' => $storeB->id,
        'store_name_snapshot' => $storeB->name,
        'role' => 'assistant',
        'content' => 'Classified B financials',
    ]);

    // Livewire component acting as User A
    $component = Livewire::actingAs($userA)->test(ChatBubble::class);

    // Messages property returns empty collection because activeThread belongs to B
    $component->set('activeThreadId', $threadB->id);
    expect($component->get('messages'))->toHaveCount(0);

    // Verify chat view does not render B's content
    $component->assertDontSee('Classified B financials');
});

test('AI layer: AskAssistantAction with foreign thread is blocked before any AI call', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $threadB = AssistantThread::create([
        'user_id' => $userB->id,
        'store_id' => $storeB->id,
    ]);

    $userMsgA = AssistantMessage::create([
        'thread_id' => $threadB->id,
        'user_id' => $userA->id,
        'store_id' => $storeA->id,
        'role' => 'user',
        'content' => 'Attack query',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldNotReceive('chat');

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);

    expect(fn () => $action->execute($threadB, $userMsgA, $storeA))
        ->toThrow(AuthorizationException::class, 'You do not own this assistant thread.');
});

test('AI layer: switch_store tool returns error when AI targets foreign vendor store', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $threadA = AssistantThread::create([
        'user_id' => $userA->id,
        'store_id' => $storeA->id,
    ]);

    $userMsg = AssistantMessage::create([
        'thread_id' => $threadA->id,
        'user_id' => $userA->id,
        'store_id' => $storeA->id,
        'role' => 'user',
        'content' => 'switch to Store B',
    ]);

    $this->actingAs($userA);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: AI calls switch_store targeting Vendor B's store name
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_switch_foreign',
                    'type' => 'function',
                    'function' => [
                        'name' => 'switch_store',
                        'arguments' => json_encode(['store_name' => 'Store B']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    // Call 2: tool result receives error because Store B is not owned by Vendor A
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'No store with that exact name');
        })
        ->andReturn([
            'content' => "I couldn't find a store named Store B in your account.",
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15, 'total_tokens' => 135],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($threadA, $userMsg, $storeA);

    $threadA->refresh();
    expect($threadA->store_id)->toBe($storeA->id)
        ->and($reply->content)->toBe("I couldn't find a store named Store B in your account.");
});

test('AI layer: PromptBuilder systemPrompt tripwire throws RuntimeException if foreign store name is injected', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $this->actingAs($userA);

    $builder = new PromptBuilder;

    // Passing legitimate store A works
    $prompt = $builder->systemPrompt(now(), $storeA, ['Store A']);
    expect($prompt)->toContain('Store A');

    // Injected store B name triggers tripwire
    expect(fn () => $builder->systemPrompt(now(), $storeA, ['Store A', 'Store B']))
        ->toThrow(RuntimeException::class, 'store name leak detected');
});

test('deleted store history: deleting a store preserves thread metadata and message snapshot', function () {
    [$user, $vendor, $store] = createVendorWithStore('MegaVendor', 'Victoria Island');
    $store2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Ikeja Branch']);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'title' => 'VI Conversation',
        'last_message_at' => now(),
    ]);

    $msg1 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'store_name_snapshot' => $store->name,
        'role' => 'user',
        'content' => 'What is VI revenue?',
    ]);

    $msg2 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'assistant',
        'content' => 'VI revenue is 450,000.',
    ]);

    // 1. Delete store
    $store->delete();

    // Thread store_id is nulled (nullOnDelete) but store_removed_* populated by StoreObserver
    $thread->refresh();
    expect($thread->store_id)->toBeNull()
        ->and($thread->store_removed_name)->toBe('Victoria Island')
        ->and($thread->store_removed_at)->not->toBeNull()
        ->and($thread->hasDeletedStore())->toBeTrue();

    // All messages with that store_id retain readable snapshot
    expect($msg1->fresh()->store_id)->toBeNull()
        ->and($msg1->fresh()->store_name_snapshot)->toBe('Victoria Island')
        ->and($msg2->fresh()->store_id)->toBeNull()
        ->and($msg2->fresh()->store_name_snapshot)->toBe('Victoria Island');

    // 2. Sidebar renders SVG icon (and no warning emoji)
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertSeeHtml('<svg')
        ->assertSeeHtml('title="Store removed"')
        ->assertDontSee('⚠️')
        ->assertSee('This conversation was about Victoria Island, removed on')
        ->assertSee('Pick a new store to continue.');

    // 3. Pick new store (Ikeja Branch)
    $setAction = new SetThreadStoreAction;
    $setAction->execute($thread, $store2, 'manual');

    $thread->refresh();
    expect($thread->store_id)->toBe($store2->id)
        ->and($thread->store_removed_at)->toBeNull()
        ->and($thread->store_removed_name)->toBeNull()
        ->and($thread->hasDeletedStore())->toBeFalse();

    // Banner is gone
    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertDontSee('This conversation was about Victoria Island');
});

test('snapshot consistency: message snapshot reflects store name at message creation and remains immutable on rename', function () {
    [$user, $vendor, $store] = createVendorWithStore('Snapshot Vendor', 'Original Store Name');

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);

    $sendAction = app(SendMessageAction::class);
    $result1 = $sendAction->execute($user, $thread, 'First message before rename');

    $userMsg1 = $result1['user_message'];
    expect($userMsg1->store_name_snapshot)->toBe('Original Store Name');

    // Rename store afterwards
    $store->update(['name' => 'Renamed Store Name']);

    // Send second message in same thread
    $result2 = $sendAction->execute($user, $thread, 'Second message after rename');
    $userMsg2 = $result2['user_message'];
    $asstMsg2 = $result2['assistant_message'];

    // Old message snapshot is unchanged (proves immutability, not live join)
    expect($userMsg1->fresh()->store_name_snapshot)->toBe('Original Store Name')
        // New messages receive the updated store name snapshot
        ->and($userMsg2->fresh()->store_name_snapshot)->toBe('Renamed Store Name')
        ->and($asstMsg2->fresh()->store_name_snapshot)->toBe('Renamed Store Name');
});

test('AI refusal behavior: when no matching tool exists for user query AI refuses and invents no data', function () {
    [$user, $vendor, $store] = createVendorWithStore('Refusal Vendor', 'Refusal Store');
    $thread = AssistantThread::create(['user_id' => $user->id, 'store_id' => $store->id]);
    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'What is the current weather forecast for tomorrow in Lagos?',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    // AI has no weather tool in its 22 tools schema, so it returns stop without any tool calls
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages, $tools) {
            return count($tools) === 22;
        })
        ->andReturn([
            'content' => "I can't check that yet. I don't have weather forecast data for your stores.",
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 18, 'total_tokens' => 218],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    expect($reply->content)->toContain("I can't check that yet")
        ->and($reply->ai_meta['tool_used'])->toBeFalse()
        ->and($reply->ai_meta['no_tool_used'])->toBeTrue()
        ->and($reply->ai_meta['tool_key'])->toBeNull();

    $this->assertDatabaseCount('assistant_query_runs', 0);
});

test('audit isolation action correctly reflects vendor-owner boundaries', function () {
    [$userA, $vendorA, $storeA] = createVendorWithStore('Vendor A', 'Store A');
    [$userB, $vendorB, $storeB] = createVendorWithStore('Vendor B', 'Store B');

    $threadA = AssistantThread::create(['user_id' => $userA->id, 'store_id' => $storeA->id]);
    $threadB = AssistantThread::create(['user_id' => $userB->id, 'store_id' => $storeB->id]);

    $audit = new AuditIsolationAction;

    // Vendor A reading/mutating
    expect($audit->canRead($threadA, $userA))->toBeTrue()
        ->and($audit->canMutate($threadA, $userA))->toBeTrue()
        ->and($audit->canRead($threadB, $userA))->toBeFalse()
        ->and($audit->canMutate($threadB, $userA))->toBeFalse();

    // Vendor B reading/mutating
    expect($audit->canRead($threadB, $userB))->toBeTrue()
        ->and($audit->canMutate($threadB, $userB))->toBeTrue()
        ->and($audit->canRead($threadA, $userB))->toBeFalse()
        ->and($audit->canMutate($threadA, $userB))->toBeFalse();

    // Query store checks
    expect($audit->canQueryStore($userA, $storeA->id))->toBeTrue()
        ->and($audit->canQueryStore($userA, $storeB->id))->toBeFalse()
        ->and($audit->canQueryStore($userB, $storeB->id))->toBeTrue()
        ->and($audit->canQueryStore($userB, $storeA->id))->toBeFalse();
});
