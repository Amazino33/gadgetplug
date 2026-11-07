<?php

namespace Tests\Support\Assistant;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Assistant\Tools\ToolResult;

/** Test-only: a cost-gated tool, to exercise the registry before the real margin tool (Phase 3). */
final class FakeMarginTool extends AssistantTool
{
    public function name(): string
    {
        return 'margin_report';
    }

    public function description(): string
    {
        return 'Test-only margin report.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function allows(AssistantContext $context): bool
    {
        return $context->canSeeCost();
    }

    public function handle(array $input, AssistantContext $context): ToolResult
    {
        return ToolResult::make(['margin_percent' => 31.5, 'vendor' => $context->vendor->name]);
    }
}
