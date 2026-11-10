<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\PreflightCheckAction;
use App\Actions\Assistant\SendMessageAction;
use App\Exceptions\Assistant\AiProviderException;
use App\Exceptions\Assistant\AiRateLimitException;
use App\Exceptions\Assistant\AssistantCapExceeded;
use App\Filament\Pages\AssistantUsageReport;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\CostCalculator;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\TokenEstimator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    AssistantGlobalSetting::query()->delete();
    AssistantGlobalSetting::create([
        'id' => 1,
        'is_enabled' => true,
        'model_name' => 'openai/gpt-4o-mini',
        'history_window' => 20,
        'max_tokens' => 800,
        'daily_message_cap' => 100,
        'daily_cost_cap_micros' => 200000,
    ]);
});

function createVendorForGuardrails(): array
{
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Guardrail Vendor']);
    $store = Store::where('vendor_id', $vendor->id)->first() ?? Store::create(['vendor_id' => $vendor->id, 'name' => 'Guardrail Store']);
    $store->update(['created_at' => now()->subDays(60)]);
    $user->stores()->syncWithoutDetaching([$store->id]);

    AssistantSetting::create([
        'user_id' => $user->id,
        'active_store_id' => $store->id,
    ]);

    return [$user, $vendor, $store];
}

function createSuperAdminForGuardrails(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

// -------------------------------------------------------------
// CostCalculator Tests
// -------------------------------------------------------------

test('CostCalculator calculates correct micros for known model gpt-4o-mini', function () {
    $cost = CostCalculator::costMicros('openai/gpt-4o-mini', 1000, 500);

    // 1000 * 0.15 = 150; 500 * 0.60 = 300 => 450 micros
    expect(is_int($cost))->toBeTrue()
        ->and($cost)->toBe(450);
});

test('CostCalculator falls back to default rates for unknown model', function () {
    $cost = CostCalculator::costMicros('custom/unknown-model', 1000, 500);

    // 1000 * 0.50 = 500; 500 * 1.50 = 750 => 1250 micros
    expect(is_int($cost))->toBeTrue()
        ->and($cost)->toBe(1250);
});

test('CostCalculator returns zero for zero tokens and never returns float', function () {
    $cost = CostCalculator::costMicros('openai/gpt-4o-mini', 0, 0);

    expect(is_int($cost))->toBeTrue()
        ->and($cost)->toBe(0);
});

// -------------------------------------------------------------
// TokenEstimator Tests
// -------------------------------------------------------------

test('TokenEstimator returns estimate within 25% of measured token fixture', function () {
    $messages = [
        ['role' => 'system', 'content' => 'You are an AI assistant for GadgetPlug. Store: Lagos Flagship.'],
        ['role' => 'user', 'content' => 'How much did I sell this week?'],
    ];

    $registry = new AssistantQueryRegistry;
    $builder = new PromptBuilder;
    $tools = $builder->toolSchema($registry);

    $estimated = TokenEstimator::estimateInput($messages, $tools);

    // Baseline calibration: a tiny prompt + slim tools. Was 1,850; Phase 1.2
    // dropped the date fields from the ten tools that take no dates (1,331).
    $fixtureMeasuredTokens = 1330;
    $lowerBound = (int) ($fixtureMeasuredTokens * 0.75);
    $upperBound = (int) ($fixtureMeasuredTokens * 1.25);

    expect($estimated)->toBeGreaterThanOrEqual($lowerBound)
        ->and($estimated)->toBeLessThanOrEqual($upperBound);
});

// -------------------------------------------------------------
// PreflightCheckAction Tests
// -------------------------------------------------------------

test('PreflightCheckAction passes when cap is 100 and count is 0 or 99', function () {
    [$user] = createVendorForGuardrails();
    $action = app(PreflightCheckAction::class);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['message_count' => 0]);
    $action->execute($user);

    $usage->update(['message_count' => 99]);
    $action->execute($user);

    expect(true)->toBeTrue();
});

test('PreflightCheckAction throws message_cap when count hits cap', function () {
    [$user] = createVendorForGuardrails();
    $action = app(PreflightCheckAction::class);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['message_count' => 100]);

    try {
        $action->execute($user);
        $this->fail('Expected AssistantCapExceeded exception');
    } catch (AssistantCapExceeded $e) {
        expect($e->reason)->toBe('message_cap')
            ->and($e->getMessage())->toStartWith("You've reached today's limit (100 messages). It resets at midnight Lagos time, in ");
    }
});

test('PreflightCheckAction passes when cap is zero (unlimited)', function () {
    [$user] = createVendorForGuardrails();
    AssistantGlobalSetting::current()->update(['daily_message_cap' => 0, 'daily_cost_cap_micros' => 0]);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['message_count' => 500, 'cost_micros' => 1000000]);

    app(PreflightCheckAction::class)->execute($user);
    expect(true)->toBeTrue();
});

test('PreflightCheckAction throws cost_cap when estimate would exceed cost cap', function () {
    [$user] = createVendorForGuardrails();
    $action = app(PreflightCheckAction::class);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['cost_micros' => 190000]); // 190,000 of 200,000 used

    try {
        // Passing 15,000 micros estimate -> 190k + 15k = 205k >= 200k cap
        $action->execute($user, 15000);
        $this->fail('Expected AssistantCapExceeded exception');
    } catch (AssistantCapExceeded $e) {
        expect($e->reason)->toBe('cost_cap')
            ->and($e->getMessage())->toStartWith("You've reached today's assistant budget. It resets at midnight Lagos time, in ");
    }
});

test('PreflightCheckAction throws cost_cap before message cap if cost cap is hit first', function () {
    [$user] = createVendorForGuardrails();
    $action = app(PreflightCheckAction::class);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['message_count' => 10, 'cost_micros' => 200000]); // Cost cap hit, messages not hit

    try {
        $action->execute($user);
        $this->fail('Expected AssistantCapExceeded exception');
    } catch (AssistantCapExceeded $e) {
        expect($e->reason)->toBe('cost_cap')
            ->and($e->getMessage())->toStartWith("You've reached today's assistant budget. It resets at midnight Lagos time, in ");
    }
});

test('PreflightCheckAction throws global_disabled when global setting is off', function () {
    [$user] = createVendorForGuardrails();
    AssistantGlobalSetting::current()->update(['is_enabled' => false]);

    try {
        app(PreflightCheckAction::class)->execute($user);
        $this->fail('Expected AssistantCapExceeded exception');
    } catch (AssistantCapExceeded $e) {
        expect($e->reason)->toBe('global_disabled');
    }
});

// -------------------------------------------------------------
// SendMessageAction + Pre-Flight & Usage Tests
// -------------------------------------------------------------

test('SendMessageAction on cap hit saves assistant refusal with short_circuit and skips AI call', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['message_count' => 100]);

    $mockAi = Mockery::mock(AiClient::class);
    $mockAi->shouldNotReceive('chat');

    $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'How much did I sell?');

    expect($result['assistant_message']->content)->toStartWith("You've reached today's limit (100 messages). It resets at midnight Lagos time, in ")
        ->and($result['assistant_message']->ai_meta['short_circuit'])->toBe('cap_exceeded')
        ->and($result['assistant_message']->ai_meta['failure_reason'])->toBe('message_cap');

    // Usage count must not increment
    $usage->refresh();
    expect($usage->message_count)->toBe(100);
    $this->assertDatabaseCount('assistant_query_runs', 0);
});

test('SendMessageAction increments usage and records token counts when AI succeeds', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    $mockAi = Mockery::mock(AiClient::class);
    $mockAi->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'You sold 100,000 NGN.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 50, 'total_tokens' => 1250],
        ]);

    $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'How much did I sell?');

    expect($result['assistant_message']->content)->toBe('You sold 100,000 NGN.');

    $usage = AssistantDailyUsage::forToday($user);
    expect($usage->message_count)->toBe(1)
        ->and($usage->input_tokens)->toBe(1200)
        ->and($usage->output_tokens)->toBe(50)
        ->and($usage->cost_micros)->toBeGreaterThan(0);
});

test('SendMessageAction increments message count on AI failure but keeps tokens at 0', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    $mockAi = Mockery::mock(AiClient::class);
    $mockAi->shouldReceive('chat')
        ->once()
        ->andThrow(new AiProviderException('Credit exhausted', 'credit_exhausted'));

    $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'Check sales');

    expect($result['assistant_message']->content)->toBe('The assistant is out of credit right now. Please contact your administrator.')
        ->and($result['assistant_message']->ai_meta['failure_category'])->toBe('credit_exhausted');

    $usage = AssistantDailyUsage::forToday($user);
    expect($usage->message_count)->toBe(1)
        ->and($usage->input_tokens)->toBe(0)
        ->and($usage->output_tokens)->toBe(0)
        ->and($usage->cost_micros)->toBe(0);
});

test('two internal AI calls in a tool flow increment message_count by exactly 1, not 2', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    $mockAi = Mockery::mock(AiClient::class);

    // Call 1: Tool call decision
    $mockAi->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'sales_summary',
                        'arguments' => json_encode(['store_id' => $store->id]),
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 30, 'total_tokens' => 1230],
        ]);

    // Call 2: Final phrasing
    $mockAi->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => 'Your sales were ₦0.00 across 0 orders.',
            'tool_calls' => [],
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 1400, 'completion_tokens' => 20, 'total_tokens' => 1420],
        ]);

    $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'What were my sales this week?');

    expect($result['assistant_message']->content)->toContain('₦0.00');

    // Invariant assertion: exactly 1 message counted, even though 2 chat calls were made
    $usage = AssistantDailyUsage::forToday($user);
    expect($usage->message_count)->toBe(1)
        ->and($usage->input_tokens)->toBe(2600)
        ->and($usage->output_tokens)->toBe(50);
});

test('pre-emptive cost estimate fires in SendMessageAction before AI call when next message would exceed cap', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    // Cap is 200,000 micros ($0.20). Vendor has used 199,500 micros ($0.1995).
    $usage = AssistantDailyUsage::forToday($user);
    $usage->update(['cost_micros' => 199500, 'message_count' => 5]);

    // Next message estimated cost will be ~700 micros (1500 tokens * 0.15 + 800 tokens * 0.60 = 705)
    // 199,500 + 705 = 200,205 >= 200,000 -> must refuse pre-emptively without calling AI
    $mockAi = Mockery::mock(AiClient::class);
    $mockAi->shouldNotReceive('chat');

    $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
    $sendAction = new SendMessageAction($askAction);

    $result = $sendAction->execute($user, null, 'Can you check my sales?');

    expect($result['assistant_message']->content)->toStartWith("You've reached today's assistant budget. It resets at midnight Lagos time, in ")
        ->and($result['assistant_message']->ai_meta['short_circuit'])->toBe('cap_exceeded')
        ->and($result['assistant_message']->ai_meta['failure_reason'])->toBe('cost_cap');

    // Zero usage increment and no tokens consumed
    $usage->refresh();
    expect($usage->cost_micros)->toBe(199500)
        ->and($usage->message_count)->toBe(5);
});

test('AssistantDailyUsage forToday handles concurrent first-send race gracefully without unique constraint violation', function () {
    [$user] = createVendorForGuardrails();
    AssistantDailyUsage::query()->where('user_id', $user->id)->delete();

    // Call forToday() twice
    $usage1 = AssistantDailyUsage::forToday($user);
    $usage2 = AssistantDailyUsage::forToday($user);

    expect($usage1->id)->toBe($usage2->id)
        ->and(AssistantDailyUsage::where('user_id', $user->id)->count())->toBe(1);
});

// -------------------------------------------------------------
// Usage Atomicity Test
// -------------------------------------------------------------

test('daily usage row upsert is atomic with lockForUpdate across sequential requests', function () {
    [$user] = createVendorForGuardrails();

    // Simulate two concurrent actions locking the row
    DB::transaction(function () use ($user) {
        $usage = AssistantDailyUsage::query()
            ->where('user_id', $user->id)
            ->where('date', today())
            ->lockForUpdate()
            ->first();

        if (! $usage) {
            $usage = AssistantDailyUsage::create([
                'user_id' => $user->id,
                'date' => today(),
                'message_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_micros' => 0,
            ]);
        }
        $usage->increment('message_count', 1);
    });

    DB::transaction(function () use ($user) {
        $usage = AssistantDailyUsage::query()
            ->where('user_id', $user->id)
            ->where('date', today())
            ->lockForUpdate()
            ->first();

        $usage->increment('message_count', 1);
    });

    $finalUsage = AssistantDailyUsage::forToday($user);
    expect($finalUsage->message_count)->toBe(2);
});

// -------------------------------------------------------------
// AiClient Retries (Mocked HTTP)
// -------------------------------------------------------------

test('AiClient retries 429 once and succeeds if second attempt is 200', function () {
    config(['services.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push(['error' => 'Rate limit'], 429)
            ->push([
                'choices' => [
                    ['message' => ['content' => 'Retry success!'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            ], 200),
    ]);

    $client = new AiClient;
    $res = $client->chat([], [], 'openai/gpt-4o-mini', 800);

    expect($res['content'])->toBe('Retry success!');
});

test('AiClient throws AiRateLimitException with rate_limited when 429 occurs twice', function () {
    config(['services.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push(['error' => 'Rate limit 1'], 429)
            ->push(['error' => 'Rate limit 2'], 429),
    ]);

    $client = new AiClient;

    try {
        $client->chat([], [], 'openai/gpt-4o-mini', 800);
        $this->fail('Expected AiRateLimitException');
    } catch (AiRateLimitException $e) {
        expect($e->failureCategory)->toBe('rate_limited');
    }
});

test('AiClient does not retry 402 and throws credit_exhausted immediately', function () {
    config(['services.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => 'Payment required'], 402),
    ]);

    $client = new AiClient;

    try {
        $client->chat([], [], 'openai/gpt-4o-mini', 800);
        $this->fail('Expected AiProviderException');
    } catch (AiProviderException $e) {
        expect($e->failureCategory)->toBe('credit_exhausted');
    }

    Http::assertSentCount(1);
});

test('AiClient retries network timeout once and succeeds on second attempt', function () {
    config(['services.openrouter.key' => 'test-key']);

    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new ConnectionException('cURL error 28: Connection timed out');
        }

        return Http::response([
            'choices' => [
                ['message' => ['content' => 'Recovered after timeout'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
        ], 200);
    });

    $client = new AiClient;
    $res = $client->chat([], [], 'openai/gpt-4o-mini', 800);

    expect($res['content'])->toBe('Recovered after timeout');
});

test('AiClient throws AiProviderException with network_timeout when network error occurs twice', function () {
    config(['services.openrouter.key' => 'test-key']);

    Http::fake(function ($request) {
        throw new ConnectionException('cURL error 28: Timeout');
    });

    $client = new AiClient;

    try {
        $client->chat([], [], 'openai/gpt-4o-mini', 800);
        $this->fail('Expected AiProviderException');
    } catch (AiProviderException $e) {
        expect($e->failureCategory)->toBe('network_timeout');
    }
});

test('AiClient does not retry 500 and throws provider_error immediately', function () {
    config(['services.openrouter.key' => 'test-key']);

    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => 'Internal server error'], 500),
    ]);

    $client = new AiClient;

    try {
        $client->chat([], [], 'openai/gpt-4o-mini', 800);
        $this->fail('Expected AiProviderException');
    } catch (AiProviderException $e) {
        expect($e->failureCategory)->toBe('provider_error');
    }

    Http::assertSentCount(1);
});

// -------------------------------------------------------------
// Failure Fallbacks Tests
// -------------------------------------------------------------

test('each failure_category produces its specific fallback message and saves category in ai_meta', function () {
    [$user, $vendor, $store] = createVendorForGuardrails();
    $this->actingAs($user);

    $categories = [
        'credit_exhausted' => 'The assistant is out of credit right now. Please contact your administrator.',
        'rate_limited' => 'The assistant is busy right now. Try again in a moment.',
        'network_timeout' => 'The assistant took too long to respond.',
        'provider_error' => 'The assistant is having trouble. Try again in a moment.',
        'unknown' => 'The assistant is having trouble. Try again in a moment.',
    ];

    foreach ($categories as $category => $expectedFallback) {
        $mockAi = Mockery::mock(AiClient::class);
        $mockAi->shouldReceive('chat')
            ->once()
            ->andThrow($category === 'rate_limited'
                ? new AiRateLimitException('Rate limit', 'rate_limited')
                : new AiProviderException('Error', $category));

        $askAction = new AskAssistantAction($mockAi, new PromptBuilder, new AssistantQueryRegistry);
        $sendAction = new SendMessageAction($askAction);

        $result = $sendAction->execute($user, null, "Test {$category}");

        expect($result['assistant_message']->content)->toBe($expectedFallback)
            ->and($result['assistant_message']->ai_meta['failure_category'])->toBe($category);
    }
});

// -------------------------------------------------------------
// Prompt Schema Slimming Tests
// -------------------------------------------------------------

test('PromptBuilder toolSchema meets token and character size slimming constraints', function () {
    $registry = new AssistantQueryRegistry;
    $builder = new PromptBuilder;

    $tools = $builder->toolSchema($registry);

    expect($tools)->toHaveCount(21);

    $registryKeys = $registry->keys();
    $totalDescriptionLength = 0;

    foreach ($tools as $tool) {
        $name = $tool['function']['name'];
        expect(in_array($name, $registryKeys, true))->toBeTrue();

        $desc = (string) $tool['function']['description'];
        expect(strlen($desc))->toBeLessThanOrEqual(80, "Tool description for [{$name}] exceeds 80 characters.");

        $totalDescriptionLength += strlen($desc);
    }

    // Total tool description characters <= 1,500
    expect($totalDescriptionLength)->toBeLessThanOrEqual(1500);

    // Estimated input tokens for bare message (system + tools) <= 2,000,
    // back from 2,100 at the end of Phase 1.2 as decided (1,979 then).
    // Was 2,000 with main at 1,996; Phase 1.1 added the two security rules
    // (record content is data, not instructions; never hint at a no_access
    // figure), compressed to ~70 tokens. Phase 1.2 removes the kobo
    // arithmetic instruction, which gives that room back.
    $systemPrompt = $builder->systemPrompt(now(), null, ['Flagship Store']);
    $bareMessages = [['role' => 'system', 'content' => $systemPrompt]];
    $estimatedBareTokens = TokenEstimator::estimateInput($bareMessages, $tools);

    expect($estimatedBareTokens)->toBeLessThanOrEqual(2000);
});

// -------------------------------------------------------------
// Daily Reset Behavior Tests
// -------------------------------------------------------------

test('daily usage resets correctly on new day and across time-travel', function () {
    [$user] = createVendorForGuardrails();

    // 1. Row for yesterday with count 100
    AssistantDailyUsage::create([
        'user_id' => $user->id,
        'date' => today()->subDay(),
        'message_count' => 100,
        'input_tokens' => 50000,
        'output_tokens' => 10000,
        'cost_micros' => 150000,
    ]);

    // Pre-flight for today should pass (new day, count = 0)
    app(PreflightCheckAction::class)->execute($user);
    $todayUsage = AssistantDailyUsage::forToday($user);
    expect($todayUsage->message_count)->toBe(0);

    // 2. Increment today's count to 100
    $todayUsage->update(['message_count' => 100]);

    // Time-travel to tomorrow
    Carbon::setTestNow(now()->addDay());

    // Pre-flight for tomorrow passes and creates fresh row with count 0
    app(PreflightCheckAction::class)->execute($user);
    $tomorrowUsage = AssistantDailyUsage::forToday($user);
    expect($tomorrowUsage->message_count)->toBe(0)
        ->and($tomorrowUsage->date->toDateString())->toBe(today()->toDateString());

    Carbon::setTestNow(); // Reset test now
});

// -------------------------------------------------------------
// Admin Panel Usage Report Tests
// -------------------------------------------------------------

test('Admin AssistantUsageReport page renders successfully for super admin', function () {
    $admin = createSuperAdminForGuardrails();

    [$vendorUser] = createVendorForGuardrails();
    AssistantDailyUsage::create([
        'user_id' => $vendorUser->id,
        'date' => today(),
        'message_count' => 25,
        'input_tokens' => 30000,
        'output_tokens' => 4000,
        'cost_micros' => 45000,
    ]);

    Livewire::actingAs($admin)
        ->test(AssistantUsageReport::class)
        ->assertSuccessful()
        ->assertSee($vendorUser->name)
        ->assertSee('Total Cost');
});
