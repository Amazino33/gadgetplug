<?php

declare(strict_types=1);

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Enums\Assistant\AnswerStatus;
use App\Exceptions\Assistant\InvalidPeriod;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantPeriod;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Phase 1.2, the Lagos day in the assistant: dates are worked out in code.
 *
 * The live case, 2026-11-10: asked "how much is today revenue?" at 08:20,
 * the model sent no dates, the old code fell back to the last 7 days, and
 * the week's ₦8,358,295 (161 orders) was given as today's. Today was ₦0.
 */

function periodAt(string $lagos): void
{
    test()->travelTo(CarbonImmutable::parse($lagos, 'Africa/Lagos'));
}

test('named periods are Lagos calendar dates, labelled for the answer', function (string $key, string $from, string $to, string $label) {
    periodAt('2026-11-10 08:20'); // a Tuesday

    $period = AssistantPeriod::named($key);

    expect($period->fromDate->toDateString())->toBe($from)
        ->and($period->toDate->toDateString())->toBe($to)
        ->and($period->label())->toBe($label);
})->with([
    ['today', '2026-11-10', '2026-11-10', 'Today (Tue 10 Nov 2026)'],
    ['yesterday', '2026-11-09', '2026-11-09', 'Yesterday (Mon 9 Nov 2026)'],
    ['this_week', '2026-11-09', '2026-11-10', 'This week (9 Nov to 10 Nov 2026)'],
    ['last_week', '2026-11-02', '2026-11-08', 'Last week (2 Nov to 8 Nov 2026)'],
    ['last_7_days', '2026-11-04', '2026-11-10', 'Last 7 days (4 Nov to 10 Nov 2026)'],
    ['this_month', '2026-11-01', '2026-11-10', 'This month (1 Nov to 10 Nov 2026)'],
    ['last_month', '2026-10-01', '2026-10-31', 'Last month (1 Oct to 31 Oct 2026)'],
    ['last_30_days', '2026-10-12', '2026-11-10', 'Last 30 days (12 Oct to 10 Nov 2026)'],
    ['this_year', '2026-01-01', '2026-11-10', 'This year (1 Jan to 10 Nov 2026)'],
]);

test('last month never overflows on the 29th, 30th or 31st', function (string $lagos, string $from, string $to) {
    periodAt($lagos);
    $period = AssistantPeriod::named('last_month');

    expect([$period->fromDate->toDateString(), $period->toDate->toDateString()])->toBe([$from, $to]);
})->with([
    ['2026-03-29 10:00', '2026-02-01', '2026-02-28'],
    ['2026-03-31 10:00', '2026-02-01', '2026-02-28'],
    ['2026-05-31 10:00', '2026-04-01', '2026-04-30'],
    ['2026-01-15 10:00', '2025-12-01', '2025-12-31'],
]);

test('half past midnight in Lagos is already the new day, and "today" starts at Lagos midnight', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-09 23:30', 'UTC')); // 00:30 Lagos, the 10th

    $today = AssistantPeriod::named('today');

    expect($today->fromDate->toDateString())->toBe('2026-11-10')
        ->and($today->from()->toDateTimeString())->toBe('2026-11-09 23:00:00')
        ->and($today->to()->lessThanOrEqualTo(now()))->toBeTrue();
});

test('plain dates: one-sided, inverted and future dates are made sensible', function () {
    periodAt('2026-11-10 08:20');

    $since = AssistantPeriod::resolve(['date_from' => '2026-11-01']);
    $swapped = AssistantPeriod::resolve(['date_from' => '2026-11-05', 'date_to' => '2026-11-02']);
    $future = AssistantPeriod::resolve(['date_from' => '2026-11-08', 'date_to' => '2026-12-25']);
    $single = AssistantPeriod::resolve(['date_to' => '2026-11-03']);
    $withTime = AssistantPeriod::resolve(['date_from' => '2026-11-03T00:00:00Z', 'date_to' => '2026-11-03']);
    $keyword = AssistantPeriod::resolve(['date_from' => 'yesterday', 'date_to' => 'today']);

    expect([$since->fromDate->toDateString(), $since->toDate->toDateString()])->toBe(['2026-11-01', '2026-11-10'])
        ->and($since->label())->toBe('1 Nov to 10 Nov 2026')
        ->and([$swapped->fromDate->toDateString(), $swapped->toDate->toDateString()])->toBe(['2026-11-02', '2026-11-05'])
        ->and($future->toDate->toDateString())->toBe('2026-11-10')
        ->and([$single->fromDate->toDateString(), $single->toDate->toDateString()])->toBe(['2026-11-03', '2026-11-03'])
        ->and($withTime->label())->toBe('Tue 3 Nov 2026')
        ->and([$keyword->fromDate->toDateString(), $keyword->toDate->toDateString()])->toBe(['2026-11-09', '2026-11-10']);
});

test('with no period and no dates, the tool default applies, never a silent week', function () {
    periodAt('2026-11-10 08:20');

    expect(AssistantPeriod::resolve([])->key)->toBe('today')
        ->and(AssistantPeriod::resolve([], 'last_30_days')->key)->toBe('last_30_days')
        ->and(AssistantPeriod::resolve(['period' => 'LAST_MONTH '])->key)->toBe('last_month');
});

test('dates it cannot read are refused, not guessed', function (array $args) {
    periodAt('2026-11-10 08:20');

    expect(fn () => AssistantPeriod::resolve($args))->toThrow(InvalidPeriod::class);
})->with([
    [['period' => 'fortnight']],
    [['date_from' => 'next tuesday']],
    [['date_from' => '2026-02-30']],
    [['date_to' => '10/11/2026']],
]);

// ── Through the ask flow ──────────────────────────────────────────────────

/** @return array{owner: User, vendor: Vendor, store: Store} */
function periodVendor(): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Oraimo Test']);
    $store = $vendor->defaultStore;
    $store->forceFill(['created_at' => now()->subDays(60)])->save();

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $store->fresh()];
}

function periodSale(array $ctx, float $total, CarbonImmutable $at): void
{
    PosSale::create([
        'reference' => 'POS-'.Str::random(8), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'cashier_id' => $ctx['owner']->id, 'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0,
        // Stored as UTC, as the app stores it; Eloquent would not convert a Lagos time.
        'total' => $total, 'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => $at->utc(),
    ]);
}

/**
 * Asks with a scripted model whose first reply calls $tool with $args; returns
 * the tool result the model was shown in its second call.
 *
 * @return array{payload: array<string, mixed>, answer: AssistantMessage}
 */
function periodAsk(array $ctx, string $tool, array $args): array
{
    $seen = new ArrayObject;
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn([
        'content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($args)]]],
    ]);
    $client->shouldReceive('chat')->once()->andReturnUsing(function (array $messages) use ($seen) {
        $seen['payload'] = json_decode((string) end($messages)['content'], true);

        return ['content' => 'ok', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]];
    });
    app()->instance(AiClient::class, $client);

    test()->actingAs($ctx['owner']);
    $answer = app(SendMessageAction::class)->execute($ctx['owner'], null, 'q', $ctx['store']->id, $ctx['vendor'])['assistant_message'];

    return ['payload' => $seen['payload'] ?? [], 'answer' => $answer->fresh()];
}

test('the live case: "today" at 08:20 with no dates sent is today\'s ₦0, not the week\'s takings', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();
    periodSale($ctx, 8358295, CarbonImmutable::now()->subDays(3)); // the week's takings, all earlier

    $result = periodAsk($ctx, 'sales_summary', []);

    expect($result['payload']['revenue'])->toEqual(0.0)
        ->and($result['payload']['period'])->toBe(['from' => '2026-11-10', 'to' => '2026-11-10', 'label' => 'Today (Tue 10 Nov 2026)'])
        ->and(AssistantQueryRun::sole()->only(['date_from', 'date_to']))
        ->toEqual(['date_from' => CarbonImmutable::parse('2026-11-10'), 'date_to' => CarbonImmutable::parse('2026-11-10')]);
});

test('the model sees the ₦ sign itself, not the JSON escape it once copied into a reply', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();

    $raw = new ArrayObject;
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn([
        'content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'sales_summary', 'arguments' => '{}']]],
    ]);
    $client->shouldReceive('chat')->once()->andReturnUsing(function (array $messages) use ($raw) {
        $raw['content'] = (string) end($messages)['content'];

        return ['content' => 'ok', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]];
    });
    app()->instance(AiClient::class, $client);

    test()->actingAs($ctx['owner']);
    app(SendMessageAction::class)->execute($ctx['owner'], null, 'q', $ctx['store']->id, $ctx['vendor']);

    expect($raw['content'])->toContain('₦0')
        ->and($raw['content'])->not->toContain('\u20a6'); // the JSON escape for ₦
});

test('asked for the last 7 days, it gets them, and says so', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();
    periodSale($ctx, 8358295, CarbonImmutable::now()->subDays(3));

    $result = periodAsk($ctx, 'sales_summary', ['period' => 'last_7_days']);

    expect($result['payload']['revenue'])->toEqual(8358295.0)
        ->and($result['payload']['period']['label'])->toBe('Last 7 days (4 Nov to 10 Nov 2026)');
});

test('a sale at 00:30 Lagos counts today, one at 23:30 the night before does not', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();
    periodSale($ctx, 1000, CarbonImmutable::parse('2026-11-10 00:30', 'Africa/Lagos'));
    periodSale($ctx, 5000, CarbonImmutable::parse('2026-11-09 23:30', 'Africa/Lagos'));

    expect(periodAsk($ctx, 'sales_summary', ['period' => 'today'])['payload']['revenue'])->toEqual(1000.0);
});

test('a period the assistant cannot read is a tool error, not a guess', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();

    $result = periodAsk($ctx, 'sales_summary', ['date_from' => 'next tuesday']);

    expect($result['payload'])->toHaveKey('error')
        ->and($result['payload']['error'])->toContain('YYYY-MM-DD')
        ->and($result['answer']->status)->toBe(AnswerStatus::ToolError);
});

test('tools that take no dates are offered none and answer without a period', function () {
    $tools = collect((new PromptBuilder)->toolSchema(new AssistantQueryRegistry))->keyBy('function.name');

    expect(array_keys($tools['sales_summary']['function']['parameters']['properties']))->toContain('period', 'date_from', 'date_to')
        ->and(array_keys($tools['stock_value']['function']['parameters']['properties']))->not->toContain('period')
        ->and(array_keys($tools['stock_value']['function']['parameters']['properties']))->not->toContain('date_from');

    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();

    expect(periodAsk($ctx, 'stock_value', [])['payload'])->not->toHaveKey('period');
});

test('the prompt gives today as the Lagos date and weekday, even before 01:00 Lagos', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-09 23:30', 'UTC')); // 00:30 Lagos, Tuesday the 10th

    $prompt = (new PromptBuilder)->systemPrompt(now(), null, []);

    expect($prompt)->toContain('Today is Tuesday 10 November 2026 (Lagos).')
        ->and($prompt)->toContain('Always state the result\'s period.label')
        ->and($prompt)->toContain('never reuse an earlier figure');
});

test('a store switch after a query in the same reply still works', function () {
    periodAt('2026-11-10 08:20');
    $ctx = periodVendor();
    $ikeja = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Oraimo Ikeja']);

    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn([
        'content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        'tool_calls' => [
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'sales_summary', 'arguments' => '{}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'switch_store', 'arguments' => json_encode(['store_name' => 'Oraimo Ikeja'])]],
        ],
    ]);
    $client->shouldReceive('chat')->once()->andReturn(['content' => 'Switched.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]);
    app()->instance(AiClient::class, $client);

    test()->actingAs($ctx['owner']);
    // Two branches: the conversation needs one picked, as in the chat bubble.
    $thread = app(CreateThreadAction::class)->execute($ctx['owner'], $ctx['vendor']);
    app(SetThreadStoreAction::class)->execute($thread, $ctx['store']);

    $result = app(SendMessageAction::class)->execute($ctx['owner'], $thread->fresh(), 'sales, then switch to Ikeja', null, $ctx['vendor']);

    expect($result['assistant_message']->fresh()->content)->toBe('Switched.')
        ->and($result['thread']->fresh()->store_id)->toBe($ikeja->id);
});
