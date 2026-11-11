<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantPeriod;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\AssistantScope;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\TokenEstimator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Phase 1.4: the question checklist on several models, side by side.
 *
 * Every question goes through the real path (SendMessageAction, the same as
 * the chat bubble and assistant:ask), once per model and prompt variant, as
 * a real user in a real business. Scored automatically where it can be:
 * the expected tools were called, the period and stores were right, and the
 * answer quotes the figure the tool returned. Tokens, cache, cost and
 * latency come from the logging built in 1.3.
 *
 * It calls the model for real and spends OpenRouter credit: run --dry-run
 * first for the estimate. Questions count toward the daily caps like any
 * other, so a large run needs the caps lifted on the test business first.
 *
 *   php artisan assistant:compare --user=owner@example.com --vendor=techhaven --store=1 --dry-run
 */
class AssistantCompareCommand extends Command
{
    protected $signature = 'assistant:compare
        {--user= : The asker: user id or email}
        {--vendor= : The business: vendor id or slug}
        {--store= : The store each conversation starts at}
        {--models=openai/gpt-4o-mini,anthropic/claude-haiku-5.5,anthropic/claude-sonnet-5.5 : Comma-separated OpenRouter model ids}
        {--variants=with_tool_lines,without_tool_lines : Prompt variants}
        {--questions=docs/ai-assistant/compare-questions.json : The questions file}
        {--only= : Comma-separated question ids to run}
        {--dry-run : Estimate the cost; call nothing}';

    protected $description = 'Run the assistant question checklist on several models side by side (spends OpenRouter credit)';

    public function handle(SendMessageAction $send): int
    {
        $user = $this->resolveUser();
        $vendor = $user ? $this->resolveVendor($user) : null;
        $store = $vendor ? Store::query()->where('vendor_id', $vendor->id)->find((int) $this->option('store')) : null;

        if (! $user || ! $vendor || ! $store) {
            $this->error('Pass --user, --vendor and --store (a store of that business).');

            return self::FAILURE;
        }

        $questions = collect(json_decode((string) File::get(base_path((string) $this->option('questions'))), true)['questions'] ?? []);
        if ($this->option('only')) {
            $only = array_map('intval', explode(',', (string) $this->option('only')));
            $questions = $questions->filter(fn ($q) => in_array((int) $q['id'], $only, true) || in_array((int) ($q['follow_up_of'] ?? 0), $only, true));
        }
        $questions = $questions->values();
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));
        $variants = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('variants')))));
        $runs = $questions->count() * count($models) * count($variants);

        $this->estimate($models, $questions->count() * count($variants));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        Auth::setUser($user);
        $results = [];

        foreach ($variants as $variant) {
            foreach ($models as $model) {
                config(['assistant.model_override' => $model, 'assistant.prompt_variant' => $variant]);
                $threads = [];
                $this->info("{$model} / {$variant}");

                foreach ($questions as $q) {
                    $thread = isset($q['follow_up_of']) ? ($threads[$q['follow_up_of']] ?? null) : null;

                    if ($thread === null) {
                        $thread = app(CreateThreadAction::class)->execute($user, $vendor);
                        app(SetThreadStoreAction::class)->execute($thread, $store, 'cli');
                        $thread = $thread->fresh();
                    }

                    try {
                        $result = $send->execute($user, $thread, (string) $q['question'], null, $vendor, 'cli');
                        $answer = $result['assistant_message']->fresh();
                        $threads[$q['id']] = $result['thread'];
                        $results[] = $this->score($q, $model, $variant, $answer, $user, $vendor);
                        $this->line(sprintf('  #%d %s', $q['id'], end($results)['pass'] ? 'pass' : 'FAIL'));
                    } catch (Throwable $e) {
                        $results[] = ['id' => $q['id'], 'model' => $model, 'variant' => $variant, 'pass' => false, 'error' => $e->getMessage()];
                        $this->line("  #{$q['id']} ERROR ".$e->getMessage());
                    }
                }
            }
        }

        config(['assistant.model_override' => null, 'assistant.prompt_variant' => null]);

        $path = $this->report($results, $questions->keyBy('id')->all(), $models, $variants);
        $this->newLine();
        $this->info("Report: {$path}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function score(array $q, string $model, string $variant, AssistantMessage $answer, User $user, Vendor $vendor): array
    {
        $meta = $answer->ai_meta ?? [];
        $runs = AssistantQueryRun::query()->where('message_id', $answer->id)->orderBy('id')->get();
        $called = $runs->pluck('query_key')->all();
        $text = (string) $answer->content;
        $checks = [];

        if (! empty($q['expect_tools'])) {
            $checks['tools'] = array_diff($q['expect_tools'], $called) === [];
        }

        if (! empty($q['expect_no_tool'])) {
            $checks['no_tool'] = $called === [];
        }

        if (! empty($q['expect_period'])) {
            $p = AssistantPeriod::named((string) $q['expect_period']);
            $checks['period'] = $runs->contains(fn (AssistantQueryRun $r) => $r->date_from?->toDateString() === $p->fromDate->toDateString() && $r->date_to?->toDateString() === $p->toDate->toDateString());
        }

        if (($q['expect_stores'] ?? null) === 'all') {
            $all = AssistantScope::for($user, $vendor)->storeIds();
            $checks['stores'] = $runs->contains(fn (AssistantQueryRun $r) => count($r->scope_json['store_ids'] ?? [$r->scope_json['store_id'] ?? null]) === count($all));
        }

        if (! empty($q['expect_figure'])) {
            $figures = [];
            foreach ($runs as $r) {
                array_walk_recursive($r->result_json, function ($v, $k) use (&$figures, $q) {
                    if (in_array($k, $q['expect_figure'], true) && is_string($v)) {
                        $figures[] = $v;
                    }
                });
            }
            $normalise = fn (string $s) => str_replace([' ', ',', 'NGN'], ['', '', '₦'], $s);
            $checks['figure'] = $figures !== [] && collect($figures)->contains(fn ($f) => str_contains($normalise($text), $normalise($f)));
        }

        // Saying it cannot do something it can is the failure seen live.
        if (! empty($q['expect_tools'])) {
            $checks['no_false_refusal'] = ! str_contains(mb_strtolower(str_replace('’', "'", $text)), "can't check that");
        }

        return [
            'id' => $q['id'],
            'model' => $model,
            'variant' => $variant,
            'pass' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'status' => $answer->status?->value,
            'tools' => $runs->map(fn (AssistantQueryRun $r) => $r->query_key.($r->success ? '' : ' (error)'))->all(),
            'answer' => $text,
            'tokens_in' => (int) ($meta['tokens_in'] ?? 0),
            'tokens_out' => (int) ($meta['tokens_out'] ?? 0),
            'cache_read' => (int) ($meta['cache_read_tokens'] ?? 0),
            'cache_write' => (int) ($meta['cache_write_tokens'] ?? 0),
            'cost_micros' => (int) ($meta['cost_micros'] ?? 0),
            'cost_source' => $meta['cost_source'] ?? null,
            'latency_ms' => (int) ($answer->latency_ms ?? 0),
            'rounds' => (int) ($meta['rounds'] ?? 0),
        ];
    }

    /** A rough upper bound: two calls per question, the full prompt each time, no cache. */
    private function estimate(array $models, int $questionsPerModel): void
    {
        $builder = new PromptBuilder;
        $input = TokenEstimator::estimateInput(
            [['role' => 'system', 'content' => $builder->systemPrompt(now(), null, ['Main Store', 'Akpaden Branch', 'Uyo Branch'])]],
            $builder->toolSchema(new AssistantQueryRegistry),
        ) + 600; // history and tool results

        $rows = [];
        $total = 0;
        foreach ($models as $model) {
            $micros = $questionsPerModel * CostCalculator::costMicros($model, 2 * $input, 250);
            $total += $micros;
            $rows[] = [$model, $questionsPerModel, number_format(2 * $input), '$'.number_format($micros / 1_000_000, 4)];
        }

        $this->table(['Model', 'Questions', 'Input tokens each (2 calls)', 'Estimated cost (no cache)'], $rows);
        $this->line('Estimated total, upper bound: $'.number_format($total / 1_000_000, 4).'. Caching and short answers bring it down.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function report(array $results, array $questions, array $models, array $variants): string
    {
        $dir = storage_path('app/assistant-compare');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');

        $md = ['# Ask GadgetPlug model comparison, '.now('Africa/Lagos')->format('j M Y H:i').' (Lagos)', ''];
        $md[] = '| Model | Variant | Passed | Avg latency | Tokens in / out | Cache read / write | Cost | Cost per question |';
        $md[] = '|---|---|---|---|---|---|---|---|';

        foreach ($variants as $variant) {
            foreach ($models as $model) {
                $set = collect($results)->where('model', $model)->where('variant', $variant);
                $n = max(1, $set->count());
                $md[] = sprintf('| %s | %s | %d / %d | %s ms | %s / %s | %s / %s | $%s | $%s |',
                    $model, $variant, $set->where('pass', true)->count(), $set->count(),
                    number_format($set->avg('latency_ms') ?? 0), number_format($set->sum('tokens_in')), number_format($set->sum('tokens_out')),
                    number_format($set->sum('cache_read')), number_format($set->sum('cache_write')),
                    number_format($set->sum('cost_micros') / 1_000_000, 4), number_format($set->sum('cost_micros') / 1_000_000 / $n, 5));
            }
        }

        $md[] = '';
        foreach (collect($results)->groupBy('id') as $id => $rows) {
            $q = $questions[$id] ?? [];
            $md[] = "## #{$id} ".($q['question'] ?? '');
            if (! empty($q['note'])) {
                $md[] = "_{$q['note']}_";
            }
            $md[] = '';
            $md[] = '| Model | Variant | Pass | Checks | Tools | Answer |';
            $md[] = '|---|---|---|---|---|---|';
            foreach ($rows as $r) {
                $checks = collect($r['checks'] ?? [])->map(fn ($ok, $k) => ($ok ? '✓' : '✗').$k)->implode(' ');
                $answer = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], mb_strimwidth((string) ($r['answer'] ?? $r['error'] ?? ''), 0, 300, '…'));
                $md[] = sprintf('| %s | %s | %s | %s | %s | %s |', $r['model'], $r['variant'], $r['pass'] ? 'pass' : '**FAIL**', $checks, implode(', ', $r['tools'] ?? []), $answer);
            }
            $md[] = '';
        }

        File::put("{$dir}/report-{$stamp}.md", implode("\n", $md));
        File::put("{$dir}/report-{$stamp}.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return "{$dir}/report-{$stamp}.md";
    }

    private function resolveUser(): ?User
    {
        $ref = trim((string) $this->option('user'));

        return $ref === '' ? null : (ctype_digit($ref) ? User::query()->find((int) $ref) : User::query()->where('email', $ref)->first());
    }

    private function resolveVendor(User $user): ?Vendor
    {
        $ref = trim((string) $this->option('vendor'));
        $vendor = ctype_digit($ref) ? Vendor::query()->find((int) $ref) : Vendor::query()->where('slug', $ref)->first();

        return $vendor && $user->getTenants(filament()->getPanel('vendor'))->contains('id', $vendor->id) ? $vendor : null;
    }
}
