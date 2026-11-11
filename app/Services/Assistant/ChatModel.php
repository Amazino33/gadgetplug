<?php

declare(strict_types=1);

namespace App\Services\Assistant;

/**
 * The one place the assistant talks to a language model (Phase 1.4).
 *
 * Messages and tools are in the OpenAI chat format; the answer is normalised
 * to the shape below whatever the provider. AiClient (OpenRouter) is the
 * implementation bound in the container; swapping provider means another
 * class behind this interface, not changes through the ask flow.
 */
interface ChatModel
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{
     *     content: ?string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     finish_reason: string,
     *     usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cache_read_tokens: int, cache_write_tokens: int, cost_usd: ?float}
     * }
     */
    public function chat(array $messages, array $tools, string $model, int $maxTokens): array;
}
