<?php

namespace App\Services\Assistant\Tools;

use App\Models\Assistant\AssistantQuestion;
use App\Models\Assistant\AssistantToolCall;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Guards\FieldStripper;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The tools the assistant may call, and the one path every call goes through.
 *
 * run() is where the shared guards live, in this order, for every tool:
 *   1. the tool exists (unknown names are an error result, not an exception)
 *   2. input is cut to the keys the schema declares — a vendor_id or user_id
 *      the model invents is dropped here, and recorded, before the tool runs
 *   3. remaining input is type-checked against the schema
 *   4. the tool's own permission check
 *   5. the tool runs, against the server-built context
 *   6. cost/profit/margin keys are stripped for users without view_cost_price
 *   7. every list is capped at assistant.row_cap
 *   8. the call is logged
 * A tool cannot opt out of any of it.
 */
final class ToolRegistry
{
    /** @var array<string, AssistantTool> */
    private array $tools = [];

    /**
     * @param  iterable<AssistantTool>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public static function fromConfig(): self
    {
        return new self(array_map(fn (string $class) => app($class), config('assistant.tools', [])));
    }

    public function register(AssistantTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AssistantTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Every tool is offered to the model, including ones this user may not
     * use. Hiding them would turn "you don't have access to that" into "I
     * have no way to know that", which is a different and less honest answer.
     * The refusal happens in run().
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (AssistantTool $tool) => $tool->definition(), $this->tools));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool, tool: ?AssistantTool, status: string}
     */
    public function run(string $name, array $input, AssistantContext $context, ?AssistantQuestion $question = null): array
    {
        $started = hrtime(true);
        $tool = $this->get($name);
        $log = [
            'tool' => mb_substr($name, 0, 64),
            'input' => $input,
            'dropped_input' => null,
        ];

        if (! $tool) {
            return $this->finish($question, $log, $started, AssistantToolCall::STATUS_UNKNOWN_TOOL, null,
                ['error' => 'unknown_tool', 'message' => "There is no tool called {$name}."], true);
        }

        [$clean, $dropped] = $this->sanitize($tool, $input);
        $log['input'] = $clean;
        $log['dropped_input'] = $dropped === [] ? null : $dropped;

        try {
            $this->validate($tool, $clean);
        } catch (ToolInputException $e) {
            return $this->finish($question, $log, $started, AssistantToolCall::STATUS_INVALID, $tool,
                ['error' => 'invalid_input', 'message' => $e->getMessage()], true, $e->getMessage());
        }

        if (! $tool->allows($context)) {
            return $this->finish($question, $log, $started, AssistantToolCall::STATUS_DENIED, $tool,
                ['error' => 'no_access', 'message' => "You don't have access to that."], true);
        }

        try {
            $result = $tool->handle($clean, $context);
        } catch (ToolDenied $e) {
            return $this->finish($question, $log, $started, AssistantToolCall::STATUS_DENIED, $tool,
                ['error' => 'no_access', 'message' => $e->getMessage()], true);
        } catch (Throwable $e) {
            Log::error('Assistant tool failed', ['tool' => $name, 'exception' => $e]);

            return $this->finish($question, $log, $started, AssistantToolCall::STATUS_ERROR, $tool,
                ['error' => 'tool_failed', 'message' => 'That look-up failed. Say so plainly; do not guess the figure.'], true, $e->getMessage());
        }

        $data = $result->data;

        if (! $context->canSeeCost()) {
            $data = FieldStripper::stripCost($data);
            $data['cost_and_profit'] = 'hidden — this user does not have access to cost, profit or margin';
        }

        [$data, $rows, $truncated] = ToolResult::cap($data, (int) config('assistant.row_cap', 25));

        if ($result->link) {
            $data['panel_link'] = $result->link;
        }

        return $this->finish($question, $log + ['result_rows' => $rows, 'truncated' => $truncated], $started,
            AssistantToolCall::STATUS_OK, $tool, $data, false);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function sanitize(AssistantTool $tool, array $input): array
    {
        $allowed = array_keys($tool->inputSchema()['properties'] ?? []);

        $clean = array_intersect_key($input, array_flip($allowed));
        $dropped = array_diff_key($input, $clean);

        return [$clean, $dropped];
    }

    /**
     * Enough of JSON Schema to hold a model to the shapes our tools declare:
     * required keys, scalar types, enums and string length. Not a general
     * validator — tools keep their schemas to these.
     *
     * @throws ToolInputException
     */
    private function validate(AssistantTool $tool, array $input): void
    {
        $schema = $tool->inputSchema();

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                throw new ToolInputException("\"{$key}\" is required.");
            }
        }

        foreach ($input as $key => $value) {
            $rule = $schema['properties'][$key] ?? [];

            if ($value === null) {
                continue;
            }

            $ok = match ($rule['type'] ?? null) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                default => true,
            };

            if (! $ok) {
                throw new ToolInputException("\"{$key}\" must be a {$rule['type']}.");
            }

            if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
                throw new ToolInputException("\"{$key}\" must be one of: ".implode(', ', $rule['enum']).'.');
            }

            if (is_string($value) && isset($rule['maxLength']) && mb_strlen($value) > $rule['maxLength']) {
                throw new ToolInputException("\"{$key}\" is too long.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $log
     * @param  array<string, mixed>  $payload
     * @return array{content: string, is_error: bool, tool: ?AssistantTool, status: string}
     */
    private function finish(?AssistantQuestion $question, array $log, int $started, string $status, ?AssistantTool $tool, array $payload, bool $isError, ?string $error = null): array
    {
        if ($question) {
            AssistantToolCall::create($log + [
                'question_id' => $question->id,
                'status' => $status,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => $error ? mb_substr($error, 0, 2000) : null,
            ]);
        }

        return [
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'is_error' => $isError,
            'tool' => $tool,
            'status' => $status,
        ];
    }
}
