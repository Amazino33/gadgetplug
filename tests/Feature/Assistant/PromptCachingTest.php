<?php

use App\Models\Assistant\AssistantQuestion;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Llm\AnthropicLanguageModel;
use App\Services\Assistant\Llm\LanguageModel;
use App\Services\Assistant\SystemPrompt;
use App\Services\Assistant\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Assistant\RecordingTransport;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/*
 * What the real SDK puts on the wire, captured before it would leave the
 * machine. The API renders tools → system → messages, so a breakpoint on the
 * first system block caches tools + rules; everything after it is uncached.
 */

beforeEach(function () {
    config(['services.anthropic.key' => 'sk-ant-test', 'assistant.model' => 'claude-sonnet-5-5']);
});

/** Sends one real SDK request for $context and returns the recorder. */
function captureRequest(AssistantContext $context, ?RecordingTransport $transport = null): RecordingTransport
{
    $transport ??= new RecordingTransport;

    (new AnthropicLanguageModel($transport))->respond(
        SystemPrompt::blocks($context),
        ToolRegistry::fromConfig()->definitions(),
        [['role' => 'user', 'content' => 'How much did we sell today?']],
    );

    return $transport;
}

test('exactly one cache breakpoint is sent, on the fixed rules, with the per-request context after it', function () {
    $ctx = assistantVendor();

    $body = captureRequest(AssistantContext::for($ctx['vendor'], $ctx['owner']))->body();

    expect($body['system'])->toHaveCount(2)
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][0]['text'])->toBe(SystemPrompt::rules())
        ->and($body['system'][1])->not->toHaveKey('cache_control')
        ->and($body['system'][1]['text'])->toStartWith('Request context')
        // Only one breakpoint anywhere: none on tools, none on messages.
        ->and(substr_count(json_encode($body), '"cache_control"'))->toBe(1)
        ->and(collect($body['tools'])->pluck('name')->all())->toBe(['sales_today', 'flag_unanswered'])
        ->and($body['tools'][0])->toHaveKey('input_schema');
});

test('the cached prefix is byte-identical across users and vendors, so one cache entry serves everyone', function () {
    $a = assistantVendor('Zeelink Phones', 'Ikeja Branch');
    $b = assistantVendor('Rival Gadgets', 'Rival Uyo');
    $cashier = assistantStaff($b['vendor'], 'storekeeper', [$b['main']]);

    $owner = captureRequest(AssistantContext::for($a['vendor'], $a['owner']))->body();
    $staff = captureRequest(AssistantContext::for($b['vendor'], $cashier))->body();

    $prefix = fn (array $body) => json_encode([$body['tools'], $body['system'][0]]);

    expect($prefix($owner))->toBe($prefix($staff))
        // The part that differs is after the breakpoint.
        ->and($owner['system'][1]['text'])->not->toBe($staff['system'][1]['text']);
});

test('nothing time- or request-dependent is inside the cached prefix', function () {
    $ctx = assistantVendor();
    CarbonImmutable::setTestNow('2026-11-06 09:00');
    $first = captureRequest(AssistantContext::for($ctx['vendor'], $ctx['owner']))->body();
    CarbonImmutable::setTestNow('2026-11-07 18:30');
    $second = captureRequest(AssistantContext::for($ctx['vendor'], $ctx['owner']))->body();
    CarbonImmutable::setTestNow();

    expect(json_encode([$first['tools'], $first['system'][0]]))->toBe(json_encode([$second['tools'], $second['system'][0]]))
        ->and($first['system'][1]['text'])->not->toBe($second['system'][1]['text']);
});

test('the request goes to the configured model with effort and the server-side fallback beta', function () {
    $ctx = assistantVendor();

    $transport = captureRequest(AssistantContext::for($ctx['vendor'], $ctx['owner']));
    $body = $transport->body();

    expect($body['model'])->toBe('claude-sonnet-5-5')
        ->and($body['output_config'])->toBe(['effort' => 'medium'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($transport->header('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body)->not->toHaveKey('thinking');
});

test('with fallbacks off the plain endpoint is used and caching is unchanged', function () {
    config(['assistant.fallbacks' => false]);
    $ctx = assistantVendor();

    $transport = captureRequest(AssistantContext::for($ctx['vendor'], $ctx['owner']));

    expect($transport->body())->not->toHaveKey('fallbacks')
        ->and($transport->header('anthropic-beta'))->not->toContain('server-side-fallback')
        ->and($transport->body()['system'][0]['cache_control'])->toBe(['type' => 'ephemeral']);
});

test('cache reads and writes reported by the API reach the question log', function () {
    $ctx = assistantVendor();
    $transport = new RecordingTransport('Hello.', [
        'input_tokens' => 52, 'output_tokens' => 9,
        'cache_read_input_tokens' => 1480, 'cache_creation_input_tokens' => 0,
    ]);
    app()->instance(LanguageModel::class, new AnthropicLanguageModel($transport));

    assistant()->ask($ctx['owner'], $ctx['vendor'], 'Hi');

    $q = AssistantQuestion::sole();
    expect($q->cache_read_tokens)->toBe(1480)
        ->and($q->cache_write_tokens)->toBe(0)
        ->and($q->input_tokens)->toBe(52)
        ->and($q->output_tokens)->toBe(9);
});
