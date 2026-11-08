<?php

declare(strict_types=1);

use App\Actions\Assistant\AskAssistantAction;
use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Actions\Assistant\Queries\CashVarianceQuery;
use App\Actions\Assistant\Queries\CustomerDebtsQuery;
use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\ProfitQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\StockValueQuery;
use App\Actions\Assistant\Queries\SupplierOweingsQuery;
use App\Actions\Assistant\SendMessageAction;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Livewire\Assistant\ChatBubble;
use App\Models\AssistantGlobalSetting;
use App\Models\AssistantMessage;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\AssistantQueryRegistry;
use App\Services\Assistant\AssistantScope;
use App\Services\Assistant\PromptBuilder;
use App\Services\VendorRoles;
use Carbon\CarbonInterface;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
 * Phase 1.1: the assistant answers for the business the panel is open in,
 * only from the stores this user may see there, only with the permissions
 * their role carries — and never shows cost, profit or margin without
 * view_cost_price.
 */

/** @return array{owner: User, vendor: Vendor, main: Store, branch: Store} */
function scopingVendor(string $name = 'Zeelink Phones'): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0]);

    $owner = User::factory()->create(['name' => "{$name} Owner"]);
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    VendorRoles::seedFor($vendor);

    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => "{$name} Ikeja"]);

    foreach ([$main, $branch] as $store) {
        $store->forceFill(['created_at' => now()->subDays(60)])->save();
    }

    return ['owner' => $owner, 'vendor' => $vendor, 'main' => $main->fresh(), 'branch' => $branch->fresh()];
}

/**
 * A staff member holding $role in $vendor, assigned to $stores. $grant is
 * added to the role, as a vendor would from the Roles screen.
 *
 * @param  array<int, Store>  $stores
 * @param  array<int, string>  $grant
 */
function scopingStaff(Vendor $vendor, string $role, array $stores, array $grant = ['use_ai_assistant']): User
{
    $user = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$user->id]);

    setPermissionsTeamId($vendor->id);
    $roleModel = Role::where('name', $role)->where('team_id', $vendor->id)->firstOrFail();
    if ($grant !== []) {
        $roleModel->givePermissionTo($grant);
    }
    $user->assignRole($roleModel);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($stores as $store) {
        $store->users()->syncWithoutDetaching([$user->id]);
    }

    return $user->fresh();
}

function scopingSale(Store $store, User $cashier, float $total): PosSale
{
    return PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $store->vendor_id,
        'store_id' => $store->id,
        'cashier_id' => $cashier->id,
        'subtotal' => $total,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => $total,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => now()->subHour(),
    ]);
}

/** Run a query as $user inside $vendor, the way AskAssistantAction does. */
function scopingRun(User $user, ?Vendor $vendor, BaseAssistantQuery $query, array $scope, array $options = []): array
{
    test()->actingAs($user);

    return AssistantScope::for($user, $vendor)->run(
        fn () => $query->execute($scope, now()->subDays(7), now(), $options)
    );
}

// ── Who may use it ────────────────────────────────────────────────────────

test('inside a business the assistant is owner-only until a role is granted use_ai_assistant', function () {
    $ctx = scopingVendor();
    // Two roles, so the grant to one cannot reach the other.
    $granted = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']], ['use_ai_assistant']);
    $notGranted = scopingStaff($ctx['vendor'], 'inventory_manager', [$ctx['main']], []);

    expect(AssistantAccess::isEnabledFor($ctx['owner'], $ctx['vendor']))->toBeTrue()
        ->and(AssistantAccess::isEnabledFor($granted, $ctx['vendor']))->toBeTrue()
        ->and(AssistantAccess::isEnabledFor($notGranted, $ctx['vendor']))->toBeFalse();

    expect(fn () => app(SendMessageAction::class)->execute($notGranted, null, 'How much today?', null, $ctx['vendor']))
        ->toThrow(AuthorizationException::class)
        ->and(AssistantMessage::count())->toBe(0);
});

test('the chat bubble is hidden in the panel for staff without use_ai_assistant, and shown for the owner', function () {
    $ctx = scopingVendor();
    $staff = scopingStaff($ctx['vendor'], 'inventory_manager', [$ctx['main']], []);

    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::actingAs($staff)->test(ChatBubble::class)->assertDontSee('How can I help you today?');
    Livewire::actingAs($ctx['owner'])->test(ChatBubble::class)->assertSee('How can I help you today?');
});

test('the three new permissions exist and no default role holds them', function () {
    scopingVendor();

    foreach (['use_ai_assistant', 'view_ai_alerts', 'view_staff_activity'] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue()
            ->and(VendorRoles::rolesWith($permission))->toBe([]);
    }
});

// ── Which stores ──────────────────────────────────────────────────────────

test('staff see their assigned branch and are refused the other, which owners used to be the only ones to see', function () {
    $ctx = scopingVendor();
    $staff = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']]);
    scopingSale($ctx['main'], $ctx['owner'], 4000);
    scopingSale($ctx['branch'], $ctx['owner'], 9000);

    $mine = scopingRun($staff, $ctx['vendor'], new SalesSummaryQuery, ['store_id' => $ctx['main']->id]);

    expect($mine['has_data'])->toBeTrue()
        ->and($mine['revenue'])->toBe(400000)
        ->and(AssistantScope::for($staff, $ctx['vendor'])->storeIds())->toBe([$ctx['main']->id])
        ->and(fn () => scopingRun($staff, $ctx['vendor'], new SalesSummaryQuery, ['store_id' => $ctx['branch']->id]))
        ->toThrow(UnauthorizedStoreAccess::class);
});

test('an owner of two businesses is answered only from the one the panel is open in', function () {
    $a = scopingVendor('Zeelink Phones');
    $b = Vendor::create(['user_id' => $a['owner']->id, 'name' => 'Second Shop']);
    $bStore = $b->defaultStore;
    $bStore->forceFill(['created_at' => now()->subDays(60)])->save();

    expect(AssistantScope::for($a['owner'], $a['vendor'])->storeIds())->not->toContain($bStore->id)
        ->and(fn () => scopingRun($a['owner'], $a['vendor'], new SalesSummaryQuery, ['store_id' => $bStore->id]))
        ->toThrow(UnauthorizedStoreAccess::class);

    // Threads belong to a business: one started in B cannot be continued from A.
    $bThread = app(CreateThreadAction::class)->execute($a['owner'], $b);
    expect($bThread->vendor_id)->toBe($b->id)
        ->and(fn () => app(SendMessageAction::class)->execute($a['owner'], $bThread, 'Hi', null, $a['vendor']))
        ->toThrow(AuthorizationException::class);

    // And the bubble in A lists only A's threads.
    $aThread = app(CreateThreadAction::class)->execute($a['owner'], $a['vendor']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($a['vendor']);

    $listed = Livewire::actingAs($a['owner'])->test(ChatBubble::class)->instance()->threads->pluck('id')->all();
    expect($listed)->toContain($aThread->id)->not->toContain($bThread->id);
});

test('a Livewire request cannot open or switch to a thread or store outside the bubble\'s business', function () {
    $a = scopingVendor('Zeelink Phones');
    $b = Vendor::create(['user_id' => $a['owner']->id, 'name' => 'Second Shop']);
    $bThread = app(CreateThreadAction::class)->execute($a['owner'], $b);

    test()->actingAs($a['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($a['vendor']);

    Livewire::actingAs($a['owner'])->test(ChatBubble::class)
        ->call('selectThread', $bThread->id)->assertForbidden();

    Livewire::actingAs($a['owner'])->test(ChatBubble::class)
        ->call('selectStore', $b->defaultStore->id)->assertForbidden();

    // The business itself is locked on the component.
    Livewire::actingAs($a['owner'])->test(ChatBubble::class)
        ->set('vendorId', $b->id);
})->throws(CannotUpdateLockedPropertyException::class);

// ── Which data, by role ───────────────────────────────────────────────────

test('each query needs the same permission as the panel screen that shows that data', function () {
    $ctx = scopingVendor();
    // storekeeper: has view_any_products and view_customer_debts, not reports or settlement.
    $keeper = scopingStaff($ctx['vendor'], 'storekeeper', [$ctx['main']]);
    $scope = ['store_id' => $ctx['main']->id];

    expect(scopingRun($keeper, $ctx['vendor'], new SalesSummaryQuery, $scope))->toBe(['has_data' => false, 'reason' => 'no_access'])
        ->and(scopingRun($keeper, $ctx['vendor'], new CashVarianceQuery, $scope)['reason'])->toBe('no_access')
        ->and(scopingRun($keeper, $ctx['vendor'], new LowStockQuery, $scope)['reason'] ?? null)->not->toBe('no_access')
        ->and(scopingRun($keeper, $ctx['vendor'], new CustomerDebtsQuery, $scope)['reason'] ?? null)->not->toBe('no_access');
});

test('profit and stock-at-cost are refused without view_cost_price, and answered once the role has it', function () {
    $ctx = scopingVendor();
    $manager = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']]);
    $scope = ['store_id' => $ctx['main']->id];
    scopingSale($ctx['main'], $ctx['owner'], 5000);

    expect(scopingRun($manager, $ctx['vendor'], new ProfitQuery, $scope))->toBe(['has_data' => false, 'reason' => 'no_access'])
        ->and(scopingRun($manager, $ctx['vendor'], new StockValueQuery, $scope))->toBe(['has_data' => false, 'reason' => 'no_access'])
        // Revenue is theirs to see.
        ->and(scopingRun($manager, $ctx['vendor'], new SalesSummaryQuery, $scope)['revenue'])->toBe(500000);

    Role::where('name', 'store_admin')->where('team_id', $ctx['vendor']->id)->first()->givePermissionTo('view_cost_price');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(scopingRun($manager->fresh(), $ctx['vendor'], new ProfitQuery, $scope)['has_data'])->toBeTrue();
});

test('owner-only data stays owner-only whatever the role holds', function () {
    $ctx = scopingVendor();
    $manager = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']], ['use_ai_assistant', 'view_cost_price']);
    $scope = ['store_id' => $ctx['main']->id];

    expect(scopingRun($manager, $ctx['vendor'], new SupplierOweingsQuery, $scope)['reason'])->toBe('no_access')
        ->and(scopingRun($ctx['owner'], $ctx['vendor'], new SupplierOweingsQuery, $scope)['reason'] ?? null)->not->toBe('no_access');
});

test('any cost, profit or margin field a query returns is stripped for users without view_cost_price', function () {
    $ctx = scopingVendor();
    $manager = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']]);

    $leaky = new class extends BaseAssistantQuery
    {
        public function supportsAggregate(): bool
        {
            return true;
        }

        protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
        {
            return ['revenue' => 100, 'unit_cost' => 60, 'items' => [['name' => 'Phone', 'profit' => 40, 'margin_percent' => 40]]];
        }
    };

    $staffSees = scopingRun($manager, $ctx['vendor'], $leaky, ['store_id' => $ctx['main']->id]);
    $ownerSees = scopingRun($ctx['owner'], $ctx['vendor'], $leaky, ['store_id' => $ctx['main']->id]);

    expect($staffSees)->toBe(['revenue' => 100, 'items' => [['name' => 'Phone']]])
        ->and($ownerSees['unit_cost'])->toBe(60)
        ->and($ownerSees['items'][0]['profit'])->toBe(40);
});

// ── End to end through the model ──────────────────────────────────────────

test('through the model: a refused query reaches the model as no_access, a foreign store as an error, and neither leaks figures', function () {
    $ctx = scopingVendor();
    $other = scopingVendor('Rival Gadgets');
    $manager = scopingStaff($ctx['vendor'], 'store_admin', [$ctx['main']]);
    scopingSale($other['main'], $other['owner'], 777000);

    $thread = app(CreateThreadAction::class)->execute($manager, $ctx['vendor']);
    $this->actingAs($manager);
    $question = AssistantMessage::create(['thread_id' => $thread->id, 'user_id' => $manager->id, 'store_id' => $ctx['main']->id, 'role' => 'user', 'content' => 'Profit, and what did Rival sell?']);

    $sent = [];
    $ai = Mockery::mock(AiClient::class);
    $ai->shouldReceive('chat')->twice()->andReturnUsing(function ($messages) use (&$sent, $other) {
        $sent[] = $messages;

        return count($sent) === 1
            ? ['content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'profit', 'arguments' => json_encode([])]],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'sales_summary', 'arguments' => json_encode(['store_id' => $other['main']->id])]],
            ]]
            : ['content' => "You don't have access to that.", 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]];
    });

    (new AskAssistantAction($ai, new PromptBuilder, new AssistantQueryRegistry))->execute($thread->fresh(), $question, $ctx['main']);

    $toolTurns = collect($sent[1])->where('role', 'tool')->values();

    expect(json_decode($toolTurns[0]['content'], true))->toBe(['has_data' => false, 'reason' => 'no_access'])
        ->and($toolTurns[1]['content'])->toContain('Unauthorized')
        ->and(json_encode($sent))->not->toContain('77700000')
        ->and(json_encode($sent))->not->toContain('Rival Gadgets Ikeja');
});
