<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\AiRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

class AiClient implements ChatModel
{
    /**
     * Sends a chat completion request to OpenRouter with automatic retries for 429 and network timeouts.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{
     *     content: ?string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     finish_reason: string,
     *     usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cache_read_tokens: int, cache_write_tokens: int, cost_usd: ?float}
     * }
     */
    public function chat(array $messages, array $tools, string $model, int $maxTokens): array
    {
        $apiKey = (string) config('services.openrouter.key');
        if (trim($apiKey) === '') {
            throw new AiProviderException('OpenRouter API key is not configured.', 'provider_error');
        }

        $payload = [
            'model' => $model,
            'messages' => self::withCaching($messages, $model),
            'max_tokens' => $maxTokens,
            // Ask OpenRouter for its usage accounting: cache tokens and the billed cost.
            'usage' => ['include' => true],
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $attempt = 0;
        $maxAttempts = 2;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $isLastAttempt = ($attempt >= $maxAttempts);

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'HTTP-Referer' => (string) config('services.openrouter.referer', config('app.url', 'http://localhost')),
                    'X-Title' => (string) config('services.openrouter.title', config('app.name', 'GadgetPlug')),
                ])
                    ->timeout(15)
                    ->post('https://openrouter.ai/api/v1/chat/completions', $payload);
            } catch (ConnectionException $e) {
                // Connection or request timeout
                if (! $isLastAttempt) {
                    $this->delay(1);

                    continue;
                }

                throw new AiProviderException(
                    'OpenRouter connection timed out: '.$e->getMessage(),
                    'network_timeout',
                    (int) $e->getCode(),
                    $e
                );
            } catch (Throwable $e) {
                // Network error / cURL error
                if (! $isLastAttempt) {
                    $this->delay(1);

                    continue;
                }

                throw new AiProviderException(
                    'OpenRouter network error: '.$e->getMessage(),
                    'network_timeout',
                    (int) $e->getCode(),
                    $e
                );
            }

            if ($response->status() === 429) {
                if (! $isLastAttempt) {
                    $this->delay(2);

                    continue;
                }

                throw new AiRateLimitException(
                    'OpenRouter rate limit exceeded (HTTP 429).',
                    'rate_limited',
                    429
                );
            }

            if ($response->status() === 402) {
                throw new AiProviderException(
                    "OpenRouter API returned HTTP 402: {$response->body()}",
                    'credit_exhausted',
                    402
                );
            }

            if (! $response->successful()) {
                throw new AiProviderException(
                    "OpenRouter API returned HTTP {$response->status()}: {$response->body()}",
                    'provider_error',
                    $response->status()
                );
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? null;

            if (! is_array($choice)) {
                throw new AiProviderException('OpenRouter response did not contain any choices.', 'provider_error');
            }

            $message = $choice['message'] ?? [];
            $usage = $data['usage'] ?? [];

            return [
                'content' => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'] ?? [],
                'finish_reason' => $choice['finish_reason'] ?? 'stop',
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                    // Prompt caching, as OpenRouter reports it (both are part of
                    // prompt_tokens). 0 when the provider does not cache.
                    'cache_read_tokens' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
                    'cache_write_tokens' => (int) ($usage['prompt_tokens_details']['cache_write_tokens'] ?? 0),
                    // What OpenRouter billed, in USD; null when it did not say.
                    'cost_usd' => isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null,
                ],
            ];
        }

        throw new AiProviderException('OpenRouter call ended unexpectedly.', 'provider_error');
    }

    /**
     * Prompt caching for Claude models through OpenRouter (Phase 1.4).
     *
     * OpenAI models cache a repeated prefix on their own. Claude caches only
     * what a request marks, so the system message is sent as two parts: the
     * fixed rules, marked cacheable, and the per-question context after
     * PromptBuilder::CONTEXT_MARKER (date, active store, the asker's stores),
     * not. The cached prefix is then tools + rules: the same for every user
     * and every business, so one cache entry serves the whole platform.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function withCaching(array $messages, string $model): array
    {
        if (! str_starts_with($model, 'anthropic/')) {
            return $messages;
        }

        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) !== 'system' || ! is_string($message['content'] ?? null)) {
                continue;
            }

            $at = strpos($message['content'], PromptBuilder::CONTEXT_MARKER);
            $rules = $at === false ? $message['content'] : rtrim(substr($message['content'], 0, $at));
            $context = $at === false ? '' : substr($message['content'], $at);

            $messages[$i]['content'] = array_values(array_filter([
                ['type' => 'text', 'text' => $rules, 'cache_control' => ['type' => 'ephemeral']],
                $context !== '' ? ['type' => 'text', 'text' => $context] : null,
            ]));

            break;
        }

        return $messages;
    }

    protected function delay(int $seconds): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        Sleep::sleep($seconds);
    }
}
