<?php

declare(strict_types=1);

use App\Enums\Assistant\AnswerStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Phase 1.3: `php artisan assistant:ask` asks as a real user in a real
 * business, through the same SendMessageAction as the chat bubble, and
 * prints the answer, the tools called, tokens, cache, cost and latency.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor} */
function askCmdVendor(string $name = 'Terminal Phones'): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0, 'daily_vendor_cap' => 100]);

    $owner = User::factory()->create(['email' => str($name)->slug().'@example.test']);
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    $vendor->defaultStore->forceFill(['created_at' => now()->subDays(60)])->save();

    return ['owner' => $owner, 'vendor' => $vendor->fresh()];
}

function askCmdModel(array $responses): void
{
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->times(count($responses))->andReturnUsing(function () use (&$responses) {
        return array_shift($responses);
    });
    app()->instance(AiClient::class, $client);
}

test('it answers as the user, through the panel path, and prints tools, tokens, cache and latency', function () {
    $ctx = askCmdVendor();
    askCmdModel([
        ['content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 20, 'cache_write_tokens' => 1200],
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'sales_summary', 'arguments' => '{}']]]],
        ['content' => 'Nothing has sold yet today.', 'tool_calls' => [], 'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 1600, 'completion_tokens' => 12, 'cache_read_tokens' => 1200]],
    ]);

    $this->artisan('assistant:ask', ['question' => 'How much did we sell today?', '--user' => $ctx['owner']->email])
        ->expectsOutputToContain('Nothing has sold yet today.')
        ->expectsOutputToContain('sales_summary')
        ->expectsOutputToContain('3100 / 32')
        ->expectsOutputToContain('1200 / 1200')
        ->expectsOutputToContain('1 of 100')
        ->assertSuccessful();

    $answer = AssistantMessage::query()->where('role', 'assistant')->sole();

    expect($answer->ai_meta['channel'])->toBe('cli')
        ->and($answer->status)->toBe(AnswerStatus::Answered)
        ->and($answer->latency_ms)->toBeInt()
        ->and(AssistantThread::sole()->vendor_id)->toBe($ctx['vendor']->id)
        ->and(AssistantVendorDailyUsage::questionsToday($ctx['vendor']->id))->toBe(1);
});

test('a follow-up continues the same conversation with --thread', function () {
    $ctx = askCmdVendor();
    askCmdModel([
        ['content' => 'Hello.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]],
        ['content' => 'Hello again.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]],
    ]);

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $ctx['owner']->id])->assertSuccessful();
    $thread = AssistantThread::sole();

    $this->artisan('assistant:ask', ['question' => 'Hi again', '--user' => $ctx['owner']->id, '--thread' => $thread->id])
        ->expectsOutputToContain('Hello again.')
        ->assertSuccessful();

    expect(AssistantThread::count())->toBe(1)
        ->and($thread->messages()->whereIn('role', ['user', 'assistant'])->count())->toBe(4);
});

test('it refuses a business the user is not part of, and asks which one when there are several', function () {
    $a = askCmdVendor('Terminal A');
    $b = askCmdVendor('Terminal B');
    $second = Vendor::create(['user_id' => $a['owner']->id, 'name' => 'Terminal A Two']);

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $a['owner']->id, '--vendor' => $b['vendor']->slug])
        ->expectsOutputToContain('not part of Terminal B')
        ->assertFailed();

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $a['owner']->id])
        ->expectsOutputToContain('more than one business')
        ->assertFailed();

    expect(AssistantMessage::count())->toBe(0)
        ->and($second->exists)->toBeTrue();
});

test('it refuses someone else\'s conversation', function () {
    $a = askCmdVendor('Terminal A');
    $b = askCmdVendor('Terminal B');
    $theirs = AssistantThread::create(['user_id' => $b['owner']->id, 'vendor_id' => $b['vendor']->id, 'last_message_at' => now()]);

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $a['owner']->id, '--thread' => $theirs->id])
        ->expectsOutputToContain('Refused')
        ->assertFailed();
});

test('it counts toward the business\'s cap and stops at it, like the panel', function () {
    $ctx = askCmdVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 1]);
    askCmdModel([['content' => 'Hello.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]]);

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $ctx['owner']->id])->assertSuccessful();

    $this->artisan('assistant:ask', ['question' => 'Hi again', '--user' => $ctx['owner']->id])
        ->expectsOutputToContain("Your business has used all 1 of today's questions")
        ->expectsOutputToContain('cap_reached')
        ->assertSuccessful();
});

test('with several branches, --store picks the branch, as choosing one in the chat bubble does', function () {
    $ctx = askCmdVendor();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Terminal Ikeja']);
    $branch->forceFill(['created_at' => now()->subDays(60)])->save();
    askCmdModel([['content' => 'Hello from Ikeja.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]]);

    // Without --store, a business with several branches is asked to pick one.
    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $ctx['owner']->id])
        ->expectsOutputToContain('Pick a store to start.')
        ->assertSuccessful();

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $ctx['owner']->id, '--store' => $branch->id])
        ->expectsOutputToContain('Hello from Ikeja.')
        ->assertSuccessful();

    expect(AssistantThread::query()->latest('id')->first()->store_id)->toBe($branch->id);
});

test('--store refuses a branch of another business', function () {
    $a = askCmdVendor('Terminal A');
    $b = askCmdVendor('Terminal B');

    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => $a['owner']->id, '--store' => $b['vendor']->defaultStore->id])
        ->expectsOutputToContain('cannot see that store')
        ->assertFailed();

    expect(AssistantMessage::count())->toBe(0);
});

test('an unknown user is an error, not a crash', function () {
    $this->artisan('assistant:ask', ['question' => 'Hi', '--user' => 'nobody@example.test'])
        ->expectsOutputToContain('No user nobody@example.test')
        ->assertFailed();
});
