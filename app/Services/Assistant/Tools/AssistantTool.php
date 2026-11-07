<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;

/**
 * One read-only question the assistant can ask the business.
 *
 * Contract for every tool:
 *  - It never writes business data.
 *  - It never takes a vendor as input. The vendor, user and stores come from
 *    the AssistantContext the server built; ToolRegistry drops any input key
 *    the schema doesn't declare before handle() is called.
 *  - allows() is its own permission check, run by the registry on every call
 *    — the model may call any tool it can see, and the refusal is code's.
 *  - Money, counts and percentages are computed here (ideally by an existing
 *    report service), never left for the model to add up. Return each amount
 *    with a *_display twin formatted by App\Support\Naira.
 *  - It states its date range and store scope in the result, so the answer
 *    can say what the figure covers.
 */
abstract class AssistantTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * JSON Schema for the input object. Never include vendor or user fields.
     *
     * @return array<string, mixed>
     */
    abstract public function inputSchema(): array;

    abstract public function allows(AssistantContext $context): bool;

    /**
     * @param  array<string, mixed>  $input  Already sanitised against inputSchema().
     *
     * @throws ToolDenied when part of the request is outside the user's access.
     */
    abstract public function handle(array $input, AssistantContext $context): ToolResult;

    /**
     * Internal tools (flag_unanswered) are bookkeeping, not data, and are left
     * out of "which tools answered this" summaries.
     */
    public function isInternal(): bool
    {
        return false;
    }

    /**
     * The definition handed to the model, in the SDK's camelCase shape.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
    }
}
