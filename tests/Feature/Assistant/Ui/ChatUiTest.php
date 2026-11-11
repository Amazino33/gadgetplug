<?php

declare(strict_types=1);

use App\Actions\Assistant\SendMessageAction;
use App\Enums\Assistant\AnswerStatus;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\AssistantVendorDailyUsage;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Assistant chat UI, Phase 1: what the chat renders, and what it never does.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-10 16:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor, main: Store, thread: AssistantThread} */
function uiVendor(string $storeName = 'Main Store'): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0, 'daily_vendor_cap' => 100]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'UI Phones']);
    $main = $vendor->defaultStore;
    $main->update(['name' => $storeName]);
    $main->forceFill(['created_at' => now()->subDays(60)])->save();

    $thread = AssistantThread::create(['user_id' => $owner->id, 'vendor_id' => $vendor->id, 'store_id' => $main->id, 'title' => 'Today', 'last_message_at' => now()]);

    return ['owner' => $owner, 'vendor' => $vendor, 'main' => $main->fresh(), 'thread' => $thread];
}

function uiSay(array $ctx, string $role, string $content, array $extra = []): AssistantMessage
{
    return AssistantMessage::create([
        'thread_id' => $ctx['thread']->id, 'user_id' => $ctx['owner']->id, 'store_id' => $ctx['main']->id,
        'store_name_snapshot' => $ctx['main']->name, 'role' => $role, 'content' => $content, ...$extra,
    ]);
}

function uiChat(array $ctx): Testable
{
    test()->actingAs($ctx['owner']);
    Filament::setTenant($ctx['vendor']);

    return Livewire::test(ChatBubble::class);
}

// ── Markdown, safely ──────────────────────────────────────────────────────

test('answers render Markdown: bold and lists, not raw asterisks', function () {
    $ctx = uiVendor();
    uiSay($ctx, 'user', 'How many Oraimo power bank');
    uiSay($ctx, 'assistant', "Here are the **Oraimo** power banks:\n\n1. Traveler 15\n2. PowerBox 400", ['status' => AnswerStatus::Answered]);

    uiChat($ctx)
        ->assertSeeHtml('<strong>Oraimo</strong>')
        ->assertSeeHtml('<li>Traveler 15</li>')
        ->assertDontSee('**Oraimo**');
});

test('a product named like an image tag shows as text, never as an element', function () {
    $ctx = uiVendor();
    uiSay($ctx, 'user', 'list products');
    uiSay($ctx, 'assistant', 'We have <img src=x onerror=alert(1)> in stock.', ['status' => AnswerStatus::Answered]);

    uiChat($ctx)
        ->assertDontSeeHtml('<img src')
        ->assertSeeHtml('&lt;img src');
});

test('Markdown tricks in a name cannot make an off-site link; a panel link still works', function () {
    $ctx = uiVendor();
    uiSay($ctx, 'user', 'list products');
    uiSay($ctx, 'assistant', 'Found **bold** [link](https://evil.example/steal) and [the product](/plug/ui-phones/products/1/edit). Also [js](javascript:alert(1)).', ['status' => AnswerStatus::Answered]);

    uiChat($ctx)
        ->assertDontSeeHtml('evil.example/steal"')
        ->assertDontSeeHtml('href="https://evil.example')
        ->assertDontSeeHtml('javascript:')
        ->assertSeeHtml('href="/plug/ui-phones/products/1/edit"');
});

test('record content shown outside answers is plain text: questions, store names, conversation titles', function () {
    $ctx = uiVendor('<b>Ikeja</b><script>alert(1)</script>');
    $ctx['thread']->update(['title' => '<img src=x onerror=alert(2)>']);
    uiSay($ctx, 'user', '<script>alert(3)</script> how much?');

    uiChat($ctx)
        ->assertDontSeeHtml('<script>alert(')
        ->assertDontSeeHtml('<img src=x')
        ->assertDontSeeHtml('<b>Ikeja</b>')
        ->assertSeeHtml('&lt;script&gt;alert(3)&lt;/script&gt;');
});

// ── Errors: friendly words, never the exception's own ─────────────────────

test('an exception\'s message never reaches the screen; it is logged in full with the question', function () {
    $ctx = uiVendor();
    Log::spy();

    $send = Mockery::mock(SendMessageAction::class);
    $send->shouldReceive('execute')->once()->andThrow(new RuntimeException('SQLSTATE[42S22]: Column secret_internal_column not found'));
    app()->instance(SendMessageAction::class, $send);

    uiChat($ctx)
        ->call('sendMessage', message: 'How much did we sell today?')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('secret_internal_column')
        ->assertSee("Couldn't get an answer. Check your connection and try again.")
        ->assertSeeHtml('retry(')
        ->assertSet('failedQuestion', 'How much did we sell today?')
        ->assertSet('thinking', false);

    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $context) => str_contains($msg, 'could not be answered')
        && $context['exception'] instanceof RuntimeException
        && array_key_exists('question_message_id', $context)
        && $context['thread_id'] === $ctx['thread']->id)->once();
});

test('a question that is too long, and no access, get their own plain words', function (Throwable $e, string $copy) {
    $ctx = uiVendor();
    $send = Mockery::mock(SendMessageAction::class);
    $send->shouldReceive('execute')->once()->andThrow($e);
    app()->instance(SendMessageAction::class, $send);

    uiChat($ctx)
        ->call('sendMessage', message: 'q')
        ->assertSee($copy)
        ->assertDontSee($e->getMessage());
})->with([
    'too long' => [fn () => ValidationException::withMessages(['content' => 'internal validation text']), 'That question is too long. Keep it under 2,000 characters.'],
    'no access' => [fn () => new AuthorizationException('internal policy text'), "The assistant isn't available to you here. Ask the shop owner if you need it."],
]);

test('a failed answer shows the backend\'s friendly words with Retry; a cap shows the limit without Retry', function () {
    $ctx = uiVendor();
    uiSay($ctx, 'user', 'How much did we sell?');
    uiSay($ctx, 'assistant', 'The assistant took too long to respond.', ['status' => AnswerStatus::ProviderError]);
    uiSay($ctx, 'user', 'And yesterday?');
    uiSay($ctx, 'assistant', "Your business has used all 100 of today's questions. They reset at midnight Lagos time, in about 8 hours.", ['status' => AnswerStatus::CapReached]);

    $html = uiChat($ctx)->html();

    expect($html)->toContain('The assistant took too long to respond.')
        ->and($html)->toContain("retry('How much did we sell?')")
        ->and($html)->toContain('They reset at midnight Lagos time')
        // One Retry on a saved answer (the failed one), none on the cap. The hidden
        // client-side error block, for a request that never came back, is separate.
        ->and(substr_count($html, '@click.stop="retry('))->toBe(1);
});

test('no access: the answer, plus a plain note on who can help', function () {
    $ctx = uiVendor();
    uiSay($ctx, 'user', 'What profit did we make?');
    uiSay($ctx, 'assistant', "You don't have access to that.", ['status' => AnswerStatus::NoAccess]);

    uiChat($ctx)->assertSeeHtml("Your role doesn't include this. Ask the shop owner if you need it.");
});

test('Retry after a failure before the model resends the same question and counts it once', function () {
    $ctx = uiVendor();
    AssistantGlobalSetting::current()->update(['is_enabled' => false]);

    $chat = uiChat($ctx);
    // Switched off mid-session: refused before anything reaches the model.
    $chat->set('messageInput', 'How much did we sell today?')->call('sendMessage');

    AssistantGlobalSetting::current()->update(['is_enabled' => true]);
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn(['content' => 'Today: ₦0.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]);
    app()->instance(AiClient::class, $client);

    $chat->call('sendMessage', message: 'How much did we sell today?')
        ->assertSet('errorMessage', null)
        ->assertSet('failedQuestion', null)
        ->assertSee('Today: ₦0.');

    expect(AssistantMessage::query()->where('role', 'user')->where('content', 'How much did we sell today?')->count())->toBe(1)
        ->and(AssistantVendorDailyUsage::questionsToday($ctx['vendor']->id))->toBe(1);
});

// ── Times, dividers and the store ─────────────────────────────────────────

test('times are Lagos times, grouped by dividers after a 10-minute gap', function () {
    $ctx = uiVendor();
    test()->travelTo(CarbonImmutable::parse('2026-11-10 13:12', 'UTC')); // 14:12 Lagos
    uiSay($ctx, 'user', 'first');
    test()->travelTo(CarbonImmutable::parse('2026-11-10 13:14', 'UTC'));
    uiSay($ctx, 'assistant', 'answer one', ['status' => AnswerStatus::Answered]);
    test()->travelTo(CarbonImmutable::parse('2026-11-10 14:30', 'UTC')); // 15:30 Lagos
    uiSay($ctx, 'user', 'second');

    $html = uiChat($ctx)->html();

    expect($html)->toContain('Today, 14:12')
        ->and($html)->toContain('Today, 15:30')
        ->and($html)->not->toContain('Today, 13:12')
        ->and(substr_count($html, 'class="gp-chat-divider"'))->toBe(2)
        // Exact times are there for a tap, hidden until then.
        ->and($html)->toContain('>14:14</time>');
});

test('the first question gets a time divider even when a store note opens the conversation', function () {
    $ctx = uiVendor();
    test()->travelTo(CarbonImmutable::parse('2026-11-10 13:10', 'UTC'));
    uiSay($ctx, 'system', '[Store context: Main Store]', ['is_prompt' => true]);
    test()->travelTo(CarbonImmutable::parse('2026-11-10 13:12', 'UTC'));
    uiSay($ctx, 'user', 'first');

    expect(uiChat($ctx)->html())->toContain('Today, 14:12');
});

test('the store appears in the header chip and where it changes, never as a raw note or a label on every message', function () {
    $ctx = uiVendor();
    $ikeja = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Ikeja Branch']);
    uiSay($ctx, 'system', '[Store context: Main Store]', ['is_prompt' => true]);
    uiSay($ctx, 'user', 'first question');
    uiSay($ctx, 'assistant', 'first answer', ['status' => AnswerStatus::Answered]);
    uiSay($ctx, 'system', '[Store context: Ikeja Branch]', ['is_prompt' => true, 'store_id' => $ikeja->id]);

    $html = uiChat($ctx)->html();

    expect($html)->not->toContain('[Store context:')
        ->and($html)->toContain('Now asking about Ikeja Branch')
        ->and($html)->not->toContain('Now asking about Main Store')
        ->and($html)->toContain('gp-chat-store-chip');
});

// ── The rename button ─────────────────────────────────────────────────────

test('a conversation title with a line break and </script> is a safe JavaScript string in the rename button', function () {
    $ctx = uiVendor();
    $title = "Line one\nLine two '\"</script><script>alert(1)</script>";
    $ctx['thread']->update(['title' => $title]);

    $chat = uiChat($ctx);
    $html = $chat->html();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and($html)->toContain('startEditingTitle('.$ctx['thread']->id.', ')
        ->and($html)->toContain('\u003C\/script\u003E');

    $chat->call('startEditingTitle', $ctx['thread']->id, $title)->assertSet('editingTitle', $title);
});

// ── Usage, focus and the one way out ──────────────────────────────────────

test('questions left show at exactly 10 and not at 11', function (int $used, bool $shown) {
    $ctx = uiVendor();
    AssistantVendorDailyUsage::create(['vendor_id' => $ctx['vendor']->id, 'date' => CarbonImmutable::parse('2026-11-10'), 'question_count' => $used]);

    $shown
        ? uiChat($ctx)->assertSee((100 - $used).' left today')
        : uiChat($ctx)->assertDontSee('left today');
})->with([[90, true], [89, false]]);

test('one close control, a launcher hidden while open, and new answers announced', function () {
    $ctx = uiVendor();
    $html = uiChat($ctx)->html();

    expect(substr_count($html, 'aria-label="Close"'))->toBe(1)
        ->and($html)->toContain('x-show="!open"')
        ->and($html)->toContain('aria-live="polite"')
        ->and($html)->toContain('role="status"')
        ->and($html)->toContain('<textarea');
});
