<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;

/**
 * The model's way of saying "I couldn't answer this". Writes nothing itself —
 * AssistantService reads the call off the turn and files the question in
 * assistant_unanswered_questions once the answer is final. Kept as a tool so
 * the reason arrives structured rather than guessed from the answer text.
 */
final class FlagUnansweredTool extends AssistantTool
{
    public const NAME = 'flag_unanswered';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Call this when you cannot answer the question with the tools and data available: no tool covers it, '
            .'the data is not recorded, or it is too ambiguous to look up. It records the question for the team '
            ."to review. Still tell the user plainly that you don't have that yet. Do not call it for a question "
            .'you answered, or when a tool said the user has no access.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => ['no_tool', 'no_data', 'ambiguous'],
                    'description' => 'no_tool: nothing can look this up yet. no_data: the business does not record it. ambiguous: unclear what was meant.',
                ],
                'detail' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'description' => 'One line on what was missing.',
                ],
            ],
            'required' => ['reason'],
            'additionalProperties' => false,
        ];
    }

    public function allows(AssistantContext $context): bool
    {
        return true;
    }

    public function isInternal(): bool
    {
        return true;
    }

    public function handle(array $input, AssistantContext $context): ToolResult
    {
        return ToolResult::make(['recorded' => true]);
    }
}
