<?php

declare(strict_types=1);

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Enums\Assistant\AnswerStatus;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantQueryRun;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\PromptBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
 * The live case, 2026-11-10: Zeelink Tech's "Itel Home" is store 6. The tools
 * took store IDs while the prompt gave names, the model sent store_id 3 (there
 * is no store 3), the tool was refused, and the reply was "I can't check that
 * yet". The tools now take store names, looked up among the asker's stores.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-10 15:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor, stores: array<string, Store>} */
function namesVendor(): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Zeelink Names']);
    $vendor->defaultStore->update(['name' => 'Zeelink Phones']);

    $stores = ['Zeelink Phones' => $vendor->defaultStore->fresh()];
    foreach (['Itel Home', 'Oraimo Store', 'Zeelink Accesories'] as $name) {
        $stores[$name] = Store::create(['vendor_id' => $vendor->id, 'name' => $name]);
    }

    foreach ($stores as $store) {
        $store->forceFill(['created_at' => now()->subDays(60)])->save();
    }

    return ['owner' => $owner, 'vendor' => $vendor, 'stores' => array_map(fn (Store $s) => $s->fresh(), $stores)];
}

function namesSale(array $ctx, Store $store, float $total): void
{
    PosSale::create([
        'reference' => 'POS-'.Str::random(8), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $store->id,
        'cashier_id' => $ctx['owner']->id, 'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0,
        'total' => $total, 'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => now()->subHour(),
    ]);
}

/**
 * Asks in a conversation set to $active, with a model whose first reply calls
 * sales_summary with $args.
 *
 * @return array{answer: AssistantMessage, run: ?AssistantQueryRun}
 */
function namesAsk(array $ctx, User $asker, Store $active, array $args, string $reply = 'model reply'): array
{
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn([
        'content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'sales_summary', 'arguments' => json_encode($args)]]],
    ]);
    $client->shouldReceive('chat')->once()->andReturn(['content' => $reply, 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]);
    app()->instance(AiClient::class, $client);

    test()->actingAs($asker);
    $thread = app(CreateThreadAction::class)->execute($asker, $ctx['vendor']);
    app(SetThreadStoreAction::class)->execute($thread, $active);

    $answer = app(SendMessageAction::class)->execute($asker, $thread->fresh(), 'how much did we sell today?', null, $ctx['vendor'])['assistant_message']->fresh();

    return ['answer' => $answer, 'run' => AssistantQueryRun::query()->where('message_id', $answer->id)->first()];
}

test('the live case: asking at "Itel Home" names the store, and gets that store\'s figure', function () {
    $ctx = namesVendor();
    namesSale($ctx, $ctx['stores']['Itel Home'], 12000);
    namesSale($ctx, $ctx['stores']['Oraimo Store'], 50000);

    $named = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['store' => 'Itel Home', 'period' => 'today']);
    $none = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['period' => 'today']);

    expect($named['run']->scope_json)->toBe(['store_id' => $ctx['stores']['Itel Home']->id])
        ->and($named['run']->result_json['revenue'])->toEqual(12000.0)
        ->and($named['answer']->status)->toBe(AnswerStatus::Answered)
        // No store named: the active one.
        ->and($none['run']->scope_json)->toBe(['store_id' => $ctx['stores']['Itel Home']->id]);
});

test('a number the model made up can no longer turn into "I can\'t check that yet"', function () {
    $ctx = namesVendor();

    $result = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['store_id' => 999999], "I can't check that yet.");

    expect($result['answer']->status)->toBe(AnswerStatus::ToolError)
        ->and($result['answer']->content)->toBe("I couldn't fetch that right now.")
        ->and($result['answer']->content)->not->toContain("can't check");
});

test('names are forgiving about case and spacing, and a short unique name is enough', function () {
    $ctx = namesVendor();

    $spaced = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Zeelink Phones'], ['store' => '  itel   HOME ']);
    $short = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Zeelink Phones'], ['store' => 'oraimo']);

    expect($spaced['run']->scope_json)->toBe(['store_id' => $ctx['stores']['Itel Home']->id])
        ->and($short['run']->scope_json)->toBe(['store_id' => $ctx['stores']['Oraimo Store']->id]);
});

test('"all" means every store the asker can see, and several names mean those stores', function () {
    $ctx = namesVendor();

    $all = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['stores' => ['all']]);
    $two = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['stores' => ['Itel Home', 'Oraimo Store']]);

    expect(collect($all['run']->scope_json['store_ids'])->sort()->values()->all())
        ->toBe(collect($ctx['stores'])->pluck('id')->sort()->values()->all())
        ->and($two['run']->scope_json['store_ids'])->toBe([$ctx['stores']['Itel Home']->id, $ctx['stores']['Oraimo Store']->id]);
});

test('an unknown or ambiguous name is answered with the right store names, not a refusal', function () {
    $ctx = namesVendor();

    $unknown = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['store' => 'Ikeja'], "I can't check that yet.");
    $ambiguous = namesAsk($ctx, $ctx['owner'], $ctx['stores']['Itel Home'], ['store' => 'Zeelink'], "I can't check that yet.");

    expect($unknown['answer']->content)->toStartWith('No store named "Ikeja". Stores: ')
        ->and($unknown['answer']->content)->toContain('"Itel Home"')
        ->and($ambiguous['answer']->content)->toContain('"Zeelink" matches more than one store');
});

test('staff can only name their own stores; another branch is simply not one of them', function () {
    $ctx = namesVendor();
    $staff = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$staff->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $role = Role::create(['name' => 'names-test', 'guard_name' => 'web', 'team_id' => $ctx['vendor']->id]);
    $role->givePermissionTo(['use_ai_assistant', 'view_inventory_reports']);
    $staff->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ctx['stores']['Itel Home']->users()->syncWithoutDetaching([$staff->id]);
    namesSale($ctx, $ctx['stores']['Oraimo Store'], 50000);

    $result = namesAsk($ctx, $staff->fresh(), $ctx['stores']['Itel Home'], ['store' => 'Oraimo Store']);
    $all = namesAsk($ctx, $staff->fresh(), $ctx['stores']['Itel Home'], ['stores' => ['all']]);

    expect($result['answer']->content)->toBe('No store named "Oraimo Store". Stores: "Itel Home", or "all".')
        ->and($all['run']->scope_json)->toBe(['store_id' => $ctx['stores']['Itel Home']->id]);
});

test('the model is offered store names, never store numbers', function () {
    $tools = (new PromptBuilder)->toolSchema(new AssistantQueryRegistry);
    $prompt = (new PromptBuilder)->systemPrompt(now(), Store::make(['name' => 'Itel Home'])->forceFill(['id' => 6]), []);

    foreach ($tools as $tool) {
        expect($tool['function']['parameters']['properties'])->toHaveKeys(['store', 'stores'])
            ->not->toHaveKey('store_id')
            ->not->toHaveKey('store_ids');
    }

    expect($prompt)->toContain('Active store: "Itel Home"')
        ->and($prompt)->not->toContain('ID:')
        ->and($prompt)->toContain('Name stores in tools');
});
