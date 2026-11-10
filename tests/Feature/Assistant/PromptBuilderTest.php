<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\QueryResult;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\AssistantThread;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('system prompt contains today date and active store name', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Flagship Store']);

    $builder = new PromptBuilder;
    $prompt = $builder->systemPrompt(now(), $store, ['Flagship Store', 'Online Store']);

    expect($prompt)
        // Today as the Lagos date and weekday (Phase 1.2).
        ->toContain('Today is '.now('Africa/Lagos')->format('l j F Y').' (Lagos).')
        ->toContain('Flagship Store')
        ->toContain('"Flagship Store", "Online Store"')
        ->toContain('GadgetPlug')
        ->toContain('quote each *_display value exactly')
        ->not->toContain('kobo');
});

test('tool schema has 20 entries with valid JSON schema and slimmed descriptions', function () {
    $registry = new AssistantQueryRegistry;
    $builder = new PromptBuilder;

    $tools = $builder->toolSchema($registry);

    expect($tools)->toHaveCount(21);

    $registryKeys = $registry->keys();

    foreach ($tools as $tool) {
        expect($tool['type'])->toBe('function')
            ->and(in_array($tool['function']['name'], $registryKeys, true))->toBeTrue()
            ->and(strlen($tool['function']['description']))->toBeLessThanOrEqual(80)
            ->and($tool['function']['parameters']['type'])->toBe('object')
            ->and($tool['function']['parameters']['properties'])->toHaveKeys(['store_id', 'store_ids']);

        // Date fields only where the tool takes dates (Phase 1.2).
        $takesDates = $registry->defaultPeriod($tool['function']['name']) !== null;
        expect(array_key_exists('period', $tool['function']['parameters']['properties']))->toBe($takesDates)
            ->and(array_key_exists('date_from', $tool['function']['parameters']['properties']))->toBe($takesDates);
    }

    $customerHistoryTool = collect($tools)->firstWhere('function.name', 'customer_history');
    expect($customerHistoryTool)->not->toBeNull()
        ->and($customerHistoryTool['function']['parameters']['properties'])->toHaveKey('customer_name');
});

test('history pulls specified window in chronological order excluding pending message', function () {
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);

    for ($i = 1; $i <= 10; $i++) {
        AssistantMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => "Message {$i}",
        ]);
    }

    $latestUserMsg = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Message 11',
    ]);

    $builder = new PromptBuilder;
    $history = $builder->history($thread, 4, $latestUserMsg->id);

    expect($history)->toHaveCount(4)
        ->and($history[0]['content'])->toBe('Message 7')
        ->and($history[1]['content'])->toBe('Message 8')
        ->and($history[2]['content'])->toBe('Message 9')
        ->and($history[3]['content'])->toBe('Message 10');
});

test('AssistantQueryRun model casts and message relationship work correctly', function () {
    $user = User::factory()->create();
    $thread = AssistantThread::create(['user_id' => $user->id]);
    $message = AssistantMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'role' => 'assistant',
        'content' => 'Sample reply',
    ]);

    $run = AssistantQueryRun::create([
        'message_id' => $message->id,
        'query_key' => 'sales_summary',
        'scope_json' => ['store_id' => 1],
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-04',
        'result_json' => ['revenue' => 50000, 'orders' => 2],
        'success' => true,
        'duration_ms' => 45,
    ]);

    expect($run->scope_json)->toBeArray()
        ->and($run->result_json)->toBeArray()
        ->and($run->success)->toBeTrue()
        ->and($run->duration_ms)->toBe(45)
        ->and($run->message->id)->toBe($message->id);
});

test('systemPrompt contains each of the reason translations and stays in sync with QueryResult::REASONS', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Demo Vendor']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Flagship Store']);

    $builder = new PromptBuilder;
    $prompt = $builder->systemPrompt(now(), $store, ['Flagship Store']);

    expect($prompt)->toContain('When a tool returns has_data: false')
        ->and($prompt)->not->toContain('always add one sentence offering what you CAN answer')
        ->and($prompt)->toContain("If data is empty, answer honestly and vary your phrasing; don't list categories.")
        ->and($prompt)->toContain("Never use filler phrases like 'Certainly!', 'I can help with that', or 'Great question'. Answer directly and concisely.")
        ->and($prompt)->toContain("customer_debts: list debtors' names and amounts if asked.")
        ->and($prompt)->toContain('For several days, use sales_by_day over the whole range.')
        ->and($prompt)->toContain('Money in tool results is naira: quote each *_display value exactly. Never calculate money yourself.')
        ->and($prompt)->not->toContain("If the user asks for 'profit' or 'margin', you must reply: 'I can only track revenue right now, not profit margins.' Never substitute revenue for profit.")
        ->and($prompt)->not->toContain('but I can tell you about')
        ->and($prompt)->toContain("If no tool can answer, say 'I can't check that yet.'")
        ->and($prompt)->toContain('You now have tools for profit, customer history, expenses, and cashier shortages. Use them when the user asks about these topics.')
        ->and($prompt)->toContain('You now have a profit tool. Use it when the user asks about margins or profit. If no data exists, state that.')
        ->and($prompt)->toContain('customer_history: pass customer_name, and say which store each purchase was in.')
        ->and($prompt)->toContain('find_products: pass query (a name, SKU or barcode). If several products match, or match is possible, list them and ask which one.')
        ->and($prompt)->toContain('cashier_shortages: name each cashier and amount. expenses: give the till-expense total.')
        ->and($prompt)->toContain('For a follow-up about an earlier result, call the same tool with the same period.');

    $expectedTranslations = [
        'no_cash_ups_yet' => 'you haven\'t done a cash-up yet',
        'store_too_new' => 'this store is too new to have that history',
        'no_procurements' => 'there are no procurement records yet',
        'no_payouts' => 'no payouts have been made yet',
        'no_commissions' => 'no affiliate commissions have been earned yet',
        'no_debts' => 'no customers owe you anything',
        'no_pickers' => 'no picker activity has been recorded yet',
        'no_sales_yet' => 'nothing has sold yet',
        'not_configured' => 'that isn\'t set up yet',
        'internal_error' => 'something went wrong on our side',
        'no_access' => "you don't have access to that",
    ];

    foreach (QueryResult::REASONS as $reason) {
        expect($prompt)->toContain($reason)
            ->and(array_key_exists($reason, $expectedTranslations))->toBeTrue("Reason [{$reason}] is missing in test translation map.")
            ->and($prompt)->toContain($expectedTranslations[$reason]);
    }
});
