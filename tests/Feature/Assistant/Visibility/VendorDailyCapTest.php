<?php

declare(strict_types=1);

use App\Actions\Assistant\PreflightCheckAction;
use App\Actions\Assistant\SendMessageAction;
use App\Enums\Assistant\AnswerStatus;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantDailyUsage;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\AssistantVendorDailyUsage;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\VendorRoles;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
 * Phase 1.3, the locked cap decision: 100 questions per business per day,
 * counted on the Lagos trading day and reset at Lagos midnight, adjustable
 * per business by the platform admin; at the limit the user is told when it
 * resets, and the model is not called.
 */

/** @return array{owner: User, vendor: Vendor, store: Store} */
function vcapVendor(string $name = 'Cap Phones'): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0, 'daily_vendor_cap' => 100]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    VendorRoles::seedFor($vendor);
    $store = $vendor->defaultStore;
    $store->forceFill(['created_at' => now()->subDays(60)])->save();

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $store->fresh()];
}

function vcapStaff(array $ctx): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$user->id]);

    setPermissionsTeamId($ctx['vendor']->id);
    $role = Role::where('name', 'store_admin')->where('team_id', $ctx['vendor']->id)->firstOrFail();
    $role->givePermissionTo(['use_ai_assistant']);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ctx['store']->users()->syncWithoutDetaching([$user->id]);

    return $user->fresh();
}

/** A model that answers "Hello." every time, and counts how often it is asked. */
function vcapModel(): object
{
    $calls = new class
    {
        public int $count = 0;
    };

    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->andReturnUsing(function () use ($calls) {
        $calls->count++;

        return ['content' => 'Hello.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5, 'total_tokens' => 105]];
    });
    app()->instance(AiClient::class, $client);

    return $calls;
}

function vcapAsk(array $ctx, User $asker, string $question = 'Hi'): AssistantMessage
{
    test()->actingAs($asker);

    return app(SendMessageAction::class)->execute($asker, null, $question, $ctx['store']->id, $ctx['vendor'])['assistant_message']->fresh();
}

function vcapFill(Vendor $vendor, int $count, ?string $date = null): void
{
    AssistantVendorDailyUsage::create([
        'vendor_id' => $vendor->id,
        'date' => CarbonImmutable::parse($date ?? CarbonImmutable::now('Africa/Lagos')->toDateString()),
        'question_count' => $count,
    ]);
}

test('the default is 100 a day for the whole business, all staff together', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-06 19:00', 'Africa/Lagos'));
    $ctx = vcapVendor();
    $staff = vcapStaff($ctx);
    $model = vcapModel();

    vcapFill($ctx['vendor'], 98);
    vcapAsk($ctx, $ctx['owner']);
    vcapAsk($ctx, $staff);
    $refused = vcapAsk($ctx, $staff);

    expect(AssistantVendorDailyUsage::questionsToday($ctx['vendor']->id))->toBe(100)
        ->and($model->count)->toBe(2)
        ->and($refused->status)->toBe(AnswerStatus::CapReached)
        ->and($refused->ai_meta['failure_reason'])->toBe('vendor_cap')
        ->and($refused->content)->toBe("Your business has used all 100 of today's questions. They reset at midnight Lagos time, in about 5 hours.");
});

test('the admin can give one business its own number, and other businesses keep the default', function () {
    $a = vcapVendor('Cap A');
    $b = vcapVendor('Cap B');
    $a['vendor']->update(['assistant_daily_cap' => 2]);
    vcapModel();

    vcapAsk($a, $a['owner']);
    vcapAsk($a, $a['owner']);

    expect(vcapAsk($a, $a['owner'])->status)->toBe(AnswerStatus::CapReached)
        ->and(vcapAsk($b, $b['owner'])->status)->toBe(AnswerStatus::Answered)
        ->and(PreflightCheckAction::vendorCap($a['vendor']->fresh()))->toBe(2)
        ->and(PreflightCheckAction::vendorCap($b['vendor']->fresh()))->toBe(100);
});

test('a business set to 0 has no daily limit', function () {
    $ctx = vcapVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 0]);
    vcapModel();
    vcapFill($ctx['vendor'], 5000);

    expect(vcapAsk($ctx, $ctx['owner'])->status)->toBe(AnswerStatus::Answered);
});

test('refusals at the cap do not count as questions', function () {
    $ctx = vcapVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 1]);
    vcapModel();

    vcapAsk($ctx, $ctx['owner']);
    vcapAsk($ctx, $ctx['owner']);
    vcapAsk($ctx, $ctx['owner']);

    expect(AssistantVendorDailyUsage::questionsToday($ctx['vendor']->id))->toBe(1);
});

test('the day resets at Lagos midnight, not UTC midnight', function () {
    $ctx = vcapVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 1]);
    vcapModel();

    // 23:30 UTC on the 6th is already 00:30 on the 7th in Lagos.
    test()->travelTo(CarbonImmutable::parse('2026-11-06 22:30', 'UTC')); // 23:30 Lagos, the 6th
    vcapAsk($ctx, $ctx['owner']);
    expect(vcapAsk($ctx, $ctx['owner'])->status)->toBe(AnswerStatus::CapReached);

    test()->travelTo(CarbonImmutable::parse('2026-11-06 23:30', 'UTC')); // 00:30 Lagos, the 7th
    expect(vcapAsk($ctx, $ctx['owner'])->status)->toBe(AnswerStatus::Answered)
        ->and(AssistantVendorDailyUsage::query()->where('vendor_id', $ctx['vendor']->id)->orderBy('date')->get()
            ->map(fn ($r) => [$r->date->toDateString(), $r->question_count])->all())
        ->toBe([['2026-11-06', 1], ['2026-11-07', 1]])
        // The per-user counter moved to the Lagos day with it.
        ->and(AssistantDailyUsage::query()->where('user_id', $ctx['owner']->id)->orderBy('date')->get()
            ->map(fn ($r) => $r->date->toDateString())->all())
        ->toBe(['2026-11-06', '2026-11-07']);
});

test('the reset time reads naturally', function (string $lagosTime, string $expected) {
    test()->travelTo(CarbonImmutable::parse("2026-11-06 {$lagosTime}", 'Africa/Lagos'));

    expect(PreflightCheckAction::untilReset())->toBe($expected);
})->with([
    ['08:00', 'in about 16 hours'],
    ['22:00', 'in about 2 hours'],
    ['23:00', 'in about an hour'],
    ['23:30', 'in about 30 minutes'],
    ['23:59:30', 'in a minute'],
]);

test('each question\'s tokens, cache and cost add up on the business\'s row', function () {
    $ctx = vcapVendor();
    vcapModel();

    vcapAsk($ctx, $ctx['owner']);
    vcapAsk($ctx, $ctx['owner']);

    $row = AssistantVendorDailyUsage::query()->where('vendor_id', $ctx['vendor']->id)->sole();

    expect($row->question_count)->toBe(2)
        ->and($row->input_tokens)->toBe(200)
        ->and($row->output_tokens)->toBe(10)
        ->and($row->cost_micros)->toBeGreaterThan(0);
});

test('in a business, the chat shows the business\'s questions left, only once 10 or fewer remain', function () {
    $ctx = vcapVendor();
    $ctx['vendor']->update(['assistant_daily_cap' => 40]);
    vcapFill($ctx['vendor'], 37);

    test()->actingAs($ctx['owner']);
    Filament::setTenant($ctx['vendor']);

    Livewire::test(ChatBubble::class)
        ->assertSet('vendorId', $ctx['vendor']->id)
        ->assertSet('questionsLeft', 3)
        ->assertSee('3 left today');

    // Plenty left: no count in the header.
    $ctx['vendor']->update(['assistant_daily_cap' => 100]);

    Livewire::test(ChatBubble::class)
        ->assertSet('questionsLeft', 63)
        ->assertDontSee('left today');
});
