<?php

namespace Tests\Support\Assistant;

use App\Services\Assistant\Llm\LanguageModel;
use App\Services\Assistant\Llm\LanguageModelException;
use App\Services\Assistant\Llm\ModelTurn;
use Closure;
use RuntimeException;

/**
 * A scripted stand-in for the model. Each respond() takes the next scripted
 * turn — a ModelTurn, a closure that builds one from the request it was
 * handed, or an exception to throw — and records the request it received, so
 * a test can assert on exactly what the model was shown (tool results above
 * all).
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var array<int, array{system: array, tools: array, messages: array}> */
    public array $requests = [];

    /** @param  array<int, ModelTurn|Closure|\Throwable>  $script */
    public function __construct(private array $script = []) {}

    public function respond(array $system, array $tools, array $messages): ModelTurn
    {
        $this->requests[] = compact('system', 'tools', 'messages');

        if ($this->script === []) {
            throw new RuntimeException('FakeLanguageModel ran out of scripted turns.');
        }

        $next = array_shift($this->script);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next instanceof Closure ? $next($system, $tools, $messages) : $next;
    }

    public static function text(string $text, int $in = 100, int $out = 20): ModelTurn
    {
        return new ModelTurn('end_turn', [['type' => 'text', 'text' => $text]], $text, [], $in, $out, 0, 0, 'fake-model');
    }

    /**
     * @param  array<int, array{0: string, 1: array<string, mixed>}>|string  $calls  [[name, input], ...] or one tool name
     */
    public static function tools(array|string $calls, array $input = [], int $in = 100, int $out = 20): ModelTurn
    {
        if (is_string($calls)) {
            $calls = [[$calls, $input]];
        }

        $uses = [];
        $content = [];

        foreach ($calls as $i => [$name, $args]) {
            $id = 'toolu_'.$i.'_'.bin2hex(random_bytes(4));
            $uses[] = ['id' => $id, 'name' => $name, 'input' => $args];
            $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $args];
        }

        return new ModelTurn('tool_use', $content, '', $uses, $in, $out, 0, 0, 'fake-model');
    }

    public static function refusal(): ModelTurn
    {
        return new ModelTurn('refusal', [], '', [], 50, 0, 0, 0, 'fake-model');
    }

    public static function failure(string $message = 'Connection refused'): LanguageModelException
    {
        return new LanguageModelException($message);
    }

    /**
     * The decoded tool_result payloads the model was handed in request $n
     * (0-based), keyed by position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toolResultsIn(int $n): array
    {
        $last = end($this->requests[$n]['messages']);

        return collect(is_array($last['content']) ? $last['content'] : [])
            ->where('type', 'tool_result')
            ->map(fn ($r) => json_decode($r['content'], true) + ['_is_error' => $r['isError']])
            ->values()
            ->all();
    }

    /** Every tool result the model saw, across all requests, as one JSON string. */
    public function everythingShown(): string
    {
        return json_encode($this->requests, JSON_UNESCAPED_UNICODE);
    }
}
