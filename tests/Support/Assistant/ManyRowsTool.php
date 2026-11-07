<?php

namespace Tests\Support\Assistant;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Assistant\Tools\ToolResult;

/** Test-only: returns 100 rows, to exercise the row cap. */
final class ManyRowsTool extends AssistantTool
{
    public function name(): string
    {
        return 'many_rows';
    }

    public function description(): string
    {
        return 'Test-only list of 100 rows.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function allows(AssistantContext $context): bool
    {
        return true;
    }

    public function handle(array $input, AssistantContext $context): ToolResult
    {
        return ToolResult::make([
            'products' => array_map(fn (int $i) => ['name' => "Product {$i}", 'unit_cost' => 100 * $i], range(1, 100)),
        ]);
    }
}
