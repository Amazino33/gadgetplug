<?php

namespace App\Services\Assistant\Llm;

/**
 * One assistant turn, reduced to what the loop needs.
 *
 * $content is kept exactly as the model returned it, because it has to go
 * back unchanged on the next request of the same question: thinking blocks
 * the model wrote between tool calls are only valid if replayed as they came.
 */
final class ModelTurn
{
    /**
     * @param  array<int, mixed>  $content  Raw content blocks (SDK objects or arrays).
     * @param  array<int, array{id: string, name: string, input: array<string, mixed>}>  $toolUses
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly array $content,
        public readonly string $text,
        public readonly array $toolUses,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly ?string $model = null,
    ) {}

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolUses !== [];
    }

    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
