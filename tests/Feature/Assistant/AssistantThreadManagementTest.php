<?php

declare(strict_types=1);

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\DeleteThreadAction;
use App\Actions\Assistant\RenameThreadAction;
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

uses(RefreshDatabase::class);

test('CreateThreadAction handles 0, 1, and 2+ stores correctly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $action = new CreateThreadAction;

    // 1. 0 stores: null store_id, one system message "No stores assigned."
    $thread0 = $action->execute($user);
    expect($thread0->store_id)->toBeNull();
    $messages0 = $thread0->messages;
    expect($messages0)->toHaveCount(1)
        ->and($messages0->first()->role)->toBe('system')
        ->and($messages0->first()->content)->toBe('No stores assigned.')
        ->and($messages0->first()->is_prompt)->toBeTrue();

    // 2. 1 store: store_id set, one system message "[Store context: {name}]"
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'TechHaven']);
    $store1 = Store::where('vendor_id', $vendor->id)->first();
    $store1->update(['name' => 'Lagos Branch']);

    $thread1 = $action->execute($user);
    expect($thread1->store_id)->toBe($store1->id);
    $messages1 = $thread1->messages;
    expect($messages1)->toHaveCount(1)
        ->and($messages1->first()->role)->toBe('system')
        ->and($messages1->first()->content)->toBe('[Store context: Lagos Branch]')
        ->and($messages1->first()->is_prompt)->toBeTrue();

    // 3. 2+ stores: store_id null, no system message (UI shows picker)
    $store2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Abuja Branch']);

    $thread2 = $action->execute($user);
    expect($thread2->store_id)->toBeNull()
        ->and($thread2->messages)->toHaveCount(0);
});

test('SetThreadStoreAction updates store_id and records system message with source', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'TechHaven']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Ikeja Branch']);

    $thread = AssistantThread::create(['user_id' => $user->id]);

    $action = new SetThreadStoreAction;
    $action->execute($thread, $store, 'manual');

    $thread->refresh();
    expect($thread->store_id)->toBe($store->id);

    $msg = $thread->messages->first();
    expect($msg)->not->toBeNull()
        ->and($msg->role)->toBe('system')
        ->and($msg->content)->toBe('[Store context: Ikeja Branch]')
        ->and($msg->is_prompt)->toBeTrue()
        ->and($msg->ai_meta['source'])->toBe('manual');
});

test('SetThreadStoreAction rejects unauthorized store from another vendor', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $vendorB = Vendor::create(['user_id' => $userB->id, 'name' => 'Shop B']);
    $foreignStore = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Foreign Branch']);

    $thread = AssistantThread::create(['user_id' => $userA->id]);

    $action = new SetThreadStoreAction;
    expect(fn () => $action->execute($thread, $foreignStore, 'manual'))
        ->toThrow(UnauthorizedStoreAccess::class);
});

test('RenameThreadAction auto-titles from first user message and limits to 80 chars', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Initial Title',
    ]);

    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'What was the total weekly revenue for my flagship store?',
    ]);

    $action = new RenameThreadAction;

    // Empty title falls back to first user message
    $action->execute($thread, '');
    $thread->refresh();
    expect($thread->title)->toBe('What was the total weekly revenue for my flagship store?');

    // Title exceeding 80 chars gets truncated
    $longTitle = str_repeat('A', 120);
    $action->execute($thread, $longTitle);
    $thread->refresh();
    expect(strlen($thread->title))->toBe(80);

    // Non-owner cannot rename
    $userOther = User::factory()->create();
    $this->actingAs($userOther);
    expect(fn () => $action->execute($thread, 'Hacked Title'))
        ->toThrow(AuthorizationException::class);
});

test('DeleteThreadAction cascades messages and rejects non-owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = AssistantThread::create(['user_id' => $user->id]);
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Message to be deleted',
    ]);

    $action = new DeleteThreadAction;

    // Non-owner throws
    $userOther = User::factory()->create();
    $this->actingAs($userOther);
    expect(fn () => $action->execute($thread))
        ->toThrow(AuthorizationException::class);

    // Owner deletes
    $this->actingAs($user);
    $action->execute($thread);

    $this->assertDatabaseMissing('assistant_threads', ['id' => $thread->id]);
    $this->assertDatabaseCount('assistant_messages', 0);
});

test('AskAssistantAction executes switch_store tool, updates thread store, and saves assistant reply', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $storeLagos = Store::create(['vendor_id' => $vendor->id, 'name' => 'Lagos Central']);
    $storeAbuja = Store::create(['vendor_id' => $vendor->id, 'name' => 'Abuja Express']);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $storeLagos->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    AssistantGlobalSetting::current()->update([
        'is_enabled' => true,
        'history_window' => 20,
        'model_name' => 'openai/gpt-4o-mini',
        'max_tokens' => 800,
    ]);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $storeLagos->id,
    ]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $storeLagos->id,
        'role' => 'user',
        'content' => 'Please switch to Abuja Express',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: AI calls switch_store with 'Abuja Express'
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages, $tools) {
            return count($tools) === 21; // 20 queries + 1 switch_store
        })
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_switch_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'switch_store',
                        'arguments' => json_encode(['store_name' => 'Abuja Express']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20, 'total_tokens' => 170],
        ]);

    // Call 2: AI confirms the switch
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'Abuja Express');
        })
        ->andReturn([
            'content' => 'I have switched the store context to Abuja Express.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 15, 'total_tokens' => 215],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $storeLagos);

    // Thread store_id is updated
    $thread->refresh();
    expect($thread->store_id)->toBe($storeAbuja->id);

    // Reply saved with new store_id snapshot
    expect($reply->store_id)->toBe($storeAbuja->id)
        ->and($reply->ai_meta['tool_key'])->toBe('switch_store')
        ->and($reply->ai_meta['tool_used'])->toBeTrue()
        ->and($reply->ai_meta['switched_to_store_id'])->toBe($storeAbuja->id);

    // System prompt message created by SetThreadStoreAction
    $this->assertDatabaseHas('assistant_messages', [
        'thread_id' => $thread->id,
        'role' => 'system',
        'content' => '[Store context: Abuja Express]',
        'is_prompt' => true,
    ]);
});

test('AskAssistantAction handles invalid switch_store store name gracefully without mutating thread', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Lagos Central']);

    AssistantSetting::create([
        'user_id' => $user->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Switch to London branch',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: AI calls switch_store with non-existent store
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_err_switch',
                    'type' => 'function',
                    'function' => [
                        'name' => 'switch_store',
                        'arguments' => json_encode(['store_name' => 'London branch']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    // Call 2: receives error payload
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'error');
        })
        ->andReturn([
            'content' => 'I could not find a store named London branch.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 12, 'total_tokens' => 162],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store);

    // Thread store_id unchanged
    $thread->refresh();
    expect($thread->store_id)->toBe($store->id)
        ->and($reply->content)->toBe('I could not find a store named London branch.');
});

test('SendMessageAction guards unassigned thread store according to store count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    AssistantSetting::create([
        'user_id' => $user->id,
        'admin_disabled' => false,
        'vendor_disabled' => false,
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);
    $mockAiClient->shouldNotReceive('chat');

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    // Case 1: 0 stores -> returns "You have no stores assigned yet."
    $thread0 = AssistantThread::create(['user_id' => $user->id, 'store_id' => null]);
    $res0 = $sendAction->execute($user, $thread0, 'Check revenue');
    expect($res0['assistant_message']->content)->toBe('You have no stores assigned yet.');

    // Case 2: 2+ stores -> returns "Pick a store to start." with is_prompt = true, no AI call
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Tech Haven']);
    $s1 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Store 1']);
    $s2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Store 2']);

    $thread2 = AssistantThread::create(['user_id' => $user->id, 'store_id' => null]);
    $res2 = $sendAction->execute($user, $thread2, 'Check revenue');
    expect($res2['assistant_message']->content)->toBe('Pick a store to start.')
        ->and($res2['assistant_message']->is_prompt)->toBeTrue();
});

test('Livewire ChatBubble component scopes threads to user and allows thread creation, rename, delete, and store selection', function () {
    $userA = User::factory()->create();
    $vendorA = Vendor::create(['user_id' => $userA->id, 'name' => 'Shop A']);
    $storeA1 = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Store A1']);
    $storeA2 = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Store A2']);

    $userB = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $userB->id, 'name' => 'Shop B']);
    $storeB = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Store B']);

    $threadA = AssistantThread::create(['user_id' => $userA->id, 'title' => 'Thread A', 'store_id' => $storeA1->id]);
    $threadB = AssistantThread::create(['user_id' => $userB->id, 'title' => 'Thread B', 'store_id' => $storeB->id]);

    AssistantSetting::create(['user_id' => $userA->id]);
    AssistantSetting::create(['user_id' => $userB->id]);

    // User A cannot see Thread B
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->assertSee('Thread A')
        ->assertDontSee('Thread B')
        // Rename Thread A
        ->call('saveThreadTitle', $threadA->id)
        // Select Store A2
        ->call('selectStore', $storeA2->id);

    $threadA->refresh();
    expect($threadA->store_id)->toBe($storeA2->id);

    // Delete Thread A
    Livewire::actingAs($userA)
        ->test(ChatBubble::class)
        ->call('deleteThread', $threadA->id);

    $this->assertDatabaseMissing('assistant_threads', ['id' => $threadA->id]);
});

test('core invariant: message.store_id is immutable across mid-thread store switches', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Invariant Vendor']);
    $lagosStore = Store::where('vendor_id', $vendor->id)->first();
    $lagosStore->update(['name' => 'Lagos Branch']);
    $abujaStore = Store::create(['vendor_id' => $vendor->id, 'name' => 'Abuja Branch']);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $lagosStore->id,
        'title' => 'Multi-store inquiry',
    ]);

    // 1. Seed 3 messages in Lagos store context
    $msg1 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $lagosStore->id,
        'role' => 'user',
        'content' => 'What were Lagos sales yesterday?',
    ]);
    $msg2 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $lagosStore->id,
        'role' => 'assistant',
        'content' => 'Lagos revenue was 150,000.',
    ]);
    $msg3 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $lagosStore->id,
        'role' => 'user',
        'content' => 'Thanks, got it.',
    ]);

    // 2. Switch thread store to Abuja
    $setAction = new SetThreadStoreAction;
    $setAction->execute($thread, $abujaStore, 'manual');

    // 3. Add new message under Abuja context
    $msg4 = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $abujaStore->id,
        'role' => 'user',
        'content' => 'Now check Abuja inventory.',
    ]);

    // 4. Assert thread.store_id is updated to Abuja
    $thread->refresh();
    expect($thread->store_id)->toBe($abujaStore->id);

    // 5. Assert the 3 prior messages STILL have Lagos store_id (immutable snapshots)
    expect($msg1->fresh()->store_id)->toBe($lagosStore->id)
        ->and($msg2->fresh()->store_id)->toBe($lagosStore->id)
        ->and($msg3->fresh()->store_id)->toBe($lagosStore->id);

    // 6. Assert new message has Abuja store_id
    expect($msg4->fresh()->store_id)->toBe($abujaStore->id);
});

test('history includes system store context messages but excludes UI prompts', function () {
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    // Regular conversation
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Hello',
        'is_prompt' => false,
    ]);

    // System store context message
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'system',
        'content' => '[Store context: Abuja Branch]',
        'is_prompt' => true,
    ]);

    // UI prompt message (Pick a store to start)
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'assistant',
        'content' => 'Pick a store to start.',
        'is_prompt' => true,
    ]);

    // UI prompt message (No stores assigned)
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'system',
        'content' => 'No stores assigned.',
        'is_prompt' => true,
    ]);

    $builder = new PromptBuilder;
    $history = $builder->history($thread, 10);

    $contents = array_column($history, 'content');

    expect($contents)->toContain('Hello')
        ->and($contents)->toContain('[Store context: Abuja Branch]')
        ->and($contents)->not->toContain('Pick a store to start.')
        ->and($contents)->not->toContain('No stores assigned.');
});

test('switch_store tool rejects ambiguous or multiple matches', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $store1 = Store::where('vendor_id', $vendor->id)->first();
    $store1->update(['name' => 'Abuja']);
    // Create a second store with same name under same or another owned vendor
    $store2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Abuja']);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $store1->id,
    ]);

    $this->actingAs($user);

    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store1->id,
        'role' => 'user',
        'content' => 'switch to Abuja',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    // Call 1: returns switch_store with 'Abuja'
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_switch_ambiguous',
                    'type' => 'function',
                    'function' => [
                        'name' => 'switch_store',
                        'arguments' => json_encode(['store_name' => 'Abuja']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    // Call 2: receives the error payload indicating ambiguous match
    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'multiple matches');
        })
        ->andReturn([
            'content' => 'There are multiple stores named Abuja. Please be more specific.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 15, 'total_tokens' => 165],
        ]);

    $action = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $action->execute($thread, $userMsg, $store1);

    // Thread store_id is NOT mutated
    $thread->refresh();
    expect($thread->store_id)->toBe($store1->id)
        ->and($reply->content)->toBe('There are multiple stores named Abuja. Please be more specific.');
});

test('fuzzy store matching switches on unique lowercase partial match and rejects multiple partial matches', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Fuzzy Vendor']);
    $storeLagos = Store::create(['vendor_id' => $vendor->id, 'name' => 'Lagos Main Branch']);
    $storeAbuja = Store::create(['vendor_id' => $vendor->id, 'name' => 'Abuja Branch']);

    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $storeLagos->id,
    ]);

    $this->actingAs($user);

    $action = app(SetThreadStoreAction::class);

    // 1. Unique lowercase partial match succeeds
    $resolvedUnique = $action->resolveStoreByName($user->id, 'abuja');
    expect($resolvedUnique['store'])->not->toBeNull()
        ->and($resolvedUnique['store']->id)->toBe($storeAbuja->id)
        ->and($resolvedUnique['error'])->toBeNull();

    // 2. Ambiguous partial match ('branch' matches both Lagos Main Branch and Abuja Branch)
    $resolvedAmbiguous = $action->resolveStoreByName($user->id, 'branch');
    expect($resolvedAmbiguous['store'])->toBeNull()
        ->and($resolvedAmbiguous['error'])->toContain('multiple matches');

    // 3. Test through AskAssistantAction with lowercase 'abuja'
    $userMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $storeLagos->id,
        'role' => 'user',
        'content' => 'switch to abuja',
    ]);

    $mockAiClient = Mockery::mock(AiClient::class);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_fuzzy_switch',
                    'type' => 'function',
                    'function' => [
                        'name' => 'switch_store',
                        'arguments' => json_encode(['store_name' => 'abuja']),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]);

    $mockAiClient->shouldReceive('chat')
        ->once()
        ->withArgs(function ($messages) {
            $last = end($messages);

            return $last['role'] === 'tool' && str_contains($last['content'], 'Abuja Branch');
        })
        ->andReturn([
            'content' => 'Switched to Abuja Branch.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 15, 'total_tokens' => 165],
        ]);

    $askAction = new AskAssistantAction($mockAiClient, new PromptBuilder, new AssistantQueryRegistry);
    $reply = $askAction->execute($thread, $userMsg, $storeLagos);

    $thread->refresh();
    expect($thread->store_id)->toBe($storeAbuja->id)
        ->and($reply->ai_meta['switched_to_store_id'])->toBe($storeAbuja->id);
});

test('Livewire ChatBubble loads next-most-recent thread on active thread deletion or empty state if none remain', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Vendor Test']);
    $store = Store::where('vendor_id', $vendor->id)->first();

    AssistantSetting::create(['user_id' => $user->id]);

    $thread1 = AssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Older Thread',
        'store_id' => $store->id,
        'last_message_at' => now()->subHours(2),
    ]);

    $thread2 = AssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Newer Thread',
        'store_id' => $store->id,
        'last_message_at' => now()->subMinute(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(ChatBubble::class);

    // Initial active thread is the newest (thread 2)
    expect($component->get('activeThreadId'))->toBe($thread2->id);

    // Delete thread 2 -> should switch activeThreadId to thread 1
    $component->call('deleteThread', $thread2->id);
    expect($component->get('activeThreadId'))->toBe($thread1->id);

    // Delete thread 1 -> should switch activeThreadId to null (empty state)
    $component->call('deleteThread', $thread1->id);
    expect($component->get('activeThreadId'))->toBeNull();
});
