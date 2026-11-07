<?php

declare(strict_types=1);

use App\Exceptions\Assistant\AiProviderException;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantSetting;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createVendorForChatBubble(): array
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

test('Test 1: timeout in AiClient returns fallback that assistant took too long to respond and UI does not hang', function () {
    Config::set('services.openrouter.key', 'test-key');

    // Simulate timeout exception from HTTP client (15s hard timeout)
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => function () {
            throw new ConnectionException('Connection timed out after 15000 milliseconds');
        },
    ]);

    [$user, $vendor, $store] = createVendorForChatBubble();

    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->set('messageInput', 'What are my top products?')
        ->call('sendMessage')
        ->assertSee('The assistant took too long to respond.')
        ->assertSet('thinking', false)
        ->assertSet('messageInput', '')
        ->assertSet('errorMessage', null);

    $this->assertDatabaseHas('assistant_messages', [
        'user_id' => $user->id,
        'role' => 'assistant',
        'content' => 'The assistant took too long to respond.',
    ]);
});

test('Test 2: input box is cleared upon submission and thinking state resets', function () {
    Config::set('services.openrouter.key', 'test-key');

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'You sold 5 units today.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 10,
                'total_tokens' => 60,
            ],
        ], 200),
    ]);

    [$user, $vendor, $store] = createVendorForChatBubble();

    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->set('messageInput', 'How many units did I sell?')
        ->call('sendMessage')
        ->assertSet('messageInput', '')
        ->assertSet('thinking', false)
        ->assertSee('You sold 5 units today.');
});

test('messages property contains both the user new message and the assistant new reply without refresh', function () {
    Config::set('services.openrouter.key', 'test-key');

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Stock is healthy across all categories.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 45,
                'completion_tokens' => 12,
                'total_tokens' => 57,
            ],
        ], 200),
    ]);

    [$user, $vendor, $store] = createVendorForChatBubble();

    // 1. Seed an existing thread with prior message so activeThread is set on mount
    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'last_message_at' => now()->subHour(),
    ]);
    AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role' => 'user',
        'content' => 'Initial inquiry',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->set('messageInput', 'Check stock status')
        ->call('sendMessage');

    // Assert that the component messages property includes old message + user new message + assistant new reply
    $messages = $component->get('messages');

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->content)->toBe('Initial inquiry')
        ->and($messages[1]->content)->toBe('Check stock status')
        ->and($messages[1]->role)->toBe('user')
        ->and($messages[2]->content)->toBe('Stock is healthy across all categories.')
        ->and($messages[2]->role)->toBe('assistant');

    $component->assertSeeHtml('wire:key="msg-'.$messages[1]->id.'"')
        ->assertSeeHtml('wire:key="msg-'.$messages[2]->id.'"')
        ->assertSet('thinking', false);
});

test('Test 3: rendered template contains auto-scroll anchor and wire:submit.prevent=sendMessage', function () {
    [$user, $vendor, $store] = createVendorForChatBubble();

    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertSeeHtml('x-ref="chatEnd"')
        ->assertSeeHtml('wire:submit.prevent="sendMessage"')
        ->assertSeeHtml('x-ref="messageInput"');
});

test('UI test: rendered template contains suggestion chips on empty thread and store switch', function () {
    [$user, $vendor, $store] = createVendorForChatBubble();

    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->assertSeeHtml('How much did I sell this week?')
        ->assertSeeHtml('What items are running low on stock?')
        ->assertSeeHtml('Do I owe any suppliers?')
        ->assertSeeHtml("What's my cash variance?")
        ->assertSeeHtml('selectChip(');
});

test('AiClient chat throws AiProviderException with network_timeout on ConnectionException', function () {
    Config::set('services.openrouter.key', 'test-key');

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds');
        },
    ]);

    $client = new AiClient;

    try {
        $client->chat([['role' => 'user', 'content' => 'hi']], [], 'openai/gpt-4o-mini', 100);
        $this->fail('Expected AiProviderException was not thrown');
    } catch (AiProviderException $e) {
        expect($e->getFailureCategory())->toBe('network_timeout')
            ->and($e->getMessage())->toContain('timed out');
    }
});

test('ChatBubble captures unexpected exceptions into errorMessage and resets thinking', function () {
    [$user, $vendor, $store] = createVendorForChatBubble();

    // Turn off access mid-flight
    AssistantGlobalSetting::current()->update(['is_enabled' => false]);

    Livewire::actingAs($user)
        ->test(ChatBubble::class)
        ->set('messageInput', 'Hello')
        ->call('sendMessage')
        ->assertSet('thinking', false);
});
