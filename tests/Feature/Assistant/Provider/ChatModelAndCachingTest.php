<?php

declare(strict_types=1);

use App\Actions\Assistant\AskAssistantAction;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\ChatModel;
use App\Services\Assistant\PromptBuilder;
use Illuminate\Support\Facades\Http;

/*
 * Phase 1.4: the model call behind one interface, prompt caching for Claude
 * through OpenRouter, and the switches the side-by-side comparison uses.
 */

test('the ask flow depends on the ChatModel interface, bound to the OpenRouter client', function () {
    expect(app(ChatModel::class))->toBeInstanceOf(AiClient::class)
        ->and((new ReflectionClass(AskAssistantAction::class))->getConstructor()->getParameters()[0]->getType()->getName())
        ->toBe(ChatModel::class);
});

test('the prompt ends with the per-question context, after a marker, so the rules are a stable prefix', function () {
    $prompt = (new PromptBuilder)->systemPrompt(now(), null, ['Main Store', 'Uyo Branch']);
    $at = strpos($prompt, PromptBuilder::CONTEXT_MARKER);
    $rules = substr($prompt, 0, $at);

    expect($at)->not->toBeFalse()
        ->and(substr($prompt, $at))->toContain('"Main Store", "Uyo Branch"')
        ->and(substr($prompt, $at))->toContain('Today is')
        // Nothing that changes per user, store or day is in the rules.
        ->and($rules)->not->toContain('Main Store')
        ->and($rules)->not->toContain('Today is')
        ->and($rules)->toBe(substr((new PromptBuilder)->systemPrompt(now()->subDays(40), null, ['Elsewhere']), 0, $at));
});

test('for Claude, the rules are sent as a cacheable part and the context after them is not', function () {
    $prompt = (new PromptBuilder)->systemPrompt(now(), null, ['Main Store']);
    $messages = AiClient::withCaching([['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => 'hi']], 'anthropic/claude-sonnet-5.5');

    expect($messages[0]['content'])->toHaveCount(2)
        ->and($messages[0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($messages[0]['content'][0]['text'])->not->toContain(PromptBuilder::CONTEXT_MARKER)
        ->and($messages[0]['content'][1])->not->toHaveKey('cache_control')
        ->and($messages[0]['content'][1]['text'])->toStartWith(PromptBuilder::CONTEXT_MARKER)
        ->and($messages[1])->toBe(['role' => 'user', 'content' => 'hi']);
});

test('other models get the messages untouched: OpenAI caches a repeated prefix on its own', function () {
    $messages = [['role' => 'system', 'content' => (new PromptBuilder)->systemPrompt(now(), null, [])]];

    expect(AiClient::withCaching($messages, 'openai/gpt-4o-mini'))->toBe($messages);
});

test('the cache marking reaches OpenRouter in the request body', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::fake(['openrouter.ai/*' => Http::response([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 2000, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1900]],
    ])]);

    $usage = (new AiClient)->chat([['role' => 'system', 'content' => (new PromptBuilder)->systemPrompt(now(), null, [])]], [], 'anthropic/claude-haiku-5.5', 50)['usage'];

    Http::assertSent(fn ($request) => ($request->data()['messages'][0]['content'][0]['cache_control']['type'] ?? null) === 'ephemeral');
    expect($usage['cache_write_tokens'])->toBe(1900);
});

test('the comparison switches: a model override, and the prompt without the two "You now have" lines', function () {
    $with = (new PromptBuilder)->systemPrompt(now(), null, []);
    config(['assistant.prompt_variant' => 'without_tool_lines']);
    $without = (new PromptBuilder)->systemPrompt(now(), null, []);
    config(['assistant.prompt_variant' => null]);

    expect($with)->toContain(PromptBuilder::TOOL_LINES)
        ->and($without)->not->toContain('You now have')
        ->and(mb_strlen($with) - mb_strlen($without))->toBe(mb_strlen(PromptBuilder::TOOL_LINES));
});
