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
        ->toContain(now()->format('Y-m-d'))
        ->toContain('Flagship Store')
        ->toContain('"Flagship Store", "Online Store"')
        ->toContain('GadgetPlug')
        ->toContain('divide ALL kobo values by 100 to get Naira before showing the user');
});

test('tool schema has 20 entries with valid JSON schema and slimmed descriptions', function () {
    $registry = new AssistantQueryRegistry;
    $builder = new PromptBuilder;

    $tools = $builder->toolSchema($registry);

    expect($tools)->toHaveCount(20);

    $registryKeys = $registry->keys();

    foreach ($tools as $tool) {
        expect($tool['type'])->toBe('function')
            ->and(in_array($tool['function']['name'], $registryKeys, true))->toBeTrue()
            ->and(strlen($tool['function']['description']))->toBeLessThanOrEqual(80)
            ->and($tool['function']['parameters']['type'])->toBe('object')
            ->and($tool['function']['parameters']['properties'])->toHaveKeys(['store_id', 'store_ids', 'date_from', 'date_to']);
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
        ->and($prompt)->toContain('If the data is empty, answer honestly. You may briefly offer alternatives, but do not repeat the same list of categories every time. Vary your phrasing.')
        ->and($prompt)->toContain("Never use filler phrases like 'Certainly!', 'I can help with that', or 'Great question'. Answer directly and concisely.")
        ->and($prompt)->toContain('If the CustomerDebtsQuery returns a debtors array, you may list their names and amounts if asked.')
        ->and($prompt)->toContain('If the user asks for multiple days, use the sales_by_day tool with a date range covering all requested days.')
        ->and($prompt)->toContain('All currency values from tools are in KOBO. This includes nested fields inside arrays (like variance or amount). You must divide ALL kobo values by 100 to get Naira before showing the user.')
        ->and($prompt)->not->toContain("If the user asks for 'profit' or 'margin', you must reply: 'I can only track revenue right now, not profit margins.' Never substitute revenue for profit.")
        ->and($prompt)->not->toContain('but I can tell you about')
        ->and($prompt)->toContain("If the user asks something no tool can answer, say 'I can't check that yet.' You may offer alternatives if relevant, but do not read a fixed list of categories.")
        ->and($prompt)->toContain('You now have tools for profit, customer history, expenses, and cashier shortages. Use them when the user asks about these topics.')
        ->and($prompt)->toContain('You now have a profit tool. Use it when the user asks about margins or profit. If no data exists, state that.')
        ->and($prompt)->toContain('For customer_history, you must pass the customer_name option based on who the user asks about.')
        ->and($prompt)->toContain('When using the customer_history tool, inform the user which store the purchase was made in, since customers can shop across multiple branches.')
        ->and($prompt)->toContain('For cashier_shortages, summarize which cashier had a variance and the amount.')
        ->and($prompt)->toContain('For expenses, explain the total spent on till expenses.')
        ->and($prompt)->toContain("If the user asks a follow-up question about a previous tool result (e.g., 'what is the name?', 'how much exactly?'), you MUST call the exact same tool with the exact same date range you just used.");

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
