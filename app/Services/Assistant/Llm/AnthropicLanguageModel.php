<?php

namespace App\Services\Assistant\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Psr\Http\Client\ClientInterface;

/**
 * The assistant's model, through the official Anthropic PHP SDK.
 *
 * Adaptive thinking is the model's default, so no thinking parameter is sent;
 * depth is set by effort. With fallbacks on, the request goes through the
 * beta endpoint so a policy refusal is retried server-side on the model's
 * default fallback within the same call.
 *
 * Prompt caching: the request renders tools → system → messages. SystemPrompt
 * puts the one cache breakpoint on the first system block (the fixed rules),
 * so the cached prefix is the tool definitions plus the rules — identical for
 * every user of every vendor — and the per-request context block after it is
 * never part of the cache key.
 */
final class AnthropicLanguageModel implements LanguageModel
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  ClientInterface|null  $transporter  HTTP client override. Null in
     *                                             production (the SDK picks one); tests pass a
     *                                             recorder to see the exact request sent.
     */
    public function __construct(private ?ClientInterface $transporter = null) {}

    public function respond(array $system, array $tools, array $messages): ModelTurn
    {
        $key = config('services.anthropic.key');

        if (! $key) {
            throw new LanguageModelException('ANTHROPIC_API_KEY is not configured.');
        }

        $client = new Client(apiKey: $key, requestOptions: array_filter([
            'timeout' => (float) config('assistant.timeout', 45),
            'maxRetries' => 2,
            'transporter' => $this->transporter,
        ], fn ($v) => $v !== null));

        $params = [
            'maxTokens' => (int) config('assistant.max_tokens', 8000),
            'messages' => $messages,
            'model' => (string) config('assistant.model'),
            'system' => $system,
            'tools' => $tools,
            'outputConfig' => ['effort' => (string) config('assistant.effort', 'medium')],
        ];

        try {
            $response = config('assistant.fallbacks', true)
                ? $client->beta->messages->create(...$params, fallbacks: 'default', betas: [self::FALLBACK_BETA])
                : $client->messages->create(...$params);
        } catch (AnthropicException $e) {
            // Every SDK failure — status, connection, timeout, retries exhausted —
            // ends the same way for the user; the message is kept for the log.
            throw new LanguageModelException($e->getMessage(), 0, $e);
        }

        $text = '';
        $toolUses = [];

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $toolUses[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        return new ModelTurn(
            stopReason: (string) $response->stopReason,
            content: $response->content,
            text: trim($text),
            toolUses: $toolUses,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            cacheReadTokens: (int) $response->usage->cacheReadInputTokens,
            cacheWriteTokens: (int) $response->usage->cacheCreationInputTokens,
            model: $response->model,
        );
    }
}
