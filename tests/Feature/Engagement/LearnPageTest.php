<?php

use App\Filament\Vendor\Pages\Dashboard;
use App\Filament\Vendor\Pages\LearnGadgetPlug;
use App\Filament\Vendor\Resources\Roles\Pages\CreateRole;
use App\Filament\Vendor\Resources\Roles\Pages\EditRole;
use App\Livewire\Engagement\LearnProgressCard;
use App\Models\AppFeature;
use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\DestinationResolver;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\LearnCatalogue;
use App\Support\Engagement\PanelDestinations;
use App\Support\Engagement\TipEligibility;
use App\Support\Engagement\TipFeedback;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** A team member at the vendor holding one default role. */
function learnStaff(array $ctx, string $role): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->attach($user->id);
    $user->stores()->syncWithoutDetaching([$ctx['store']->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $user->assignRole(Role::where('name', $role)->where('team_id', $ctx['vendor']->id)->firstOrFail());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * What a new request would see: drops the per-request memos of the usage
 * cache and the Learn list. Not forgetScopedInstances(), which also drops
 * Filament's manager and leaves the facade holding a tenant nobody reads.
 */
function learnFreshRequest(): void
{
    app()->forgetInstance(FeatureUsage::class);
    app()->forgetInstance(LearnCatalogue::class);
}

function learnFeatures(User $user, Vendor $vendor): array
{
    actInTipsPanel($user, $vendor);

    return collect(app(LearnCatalogue::class)->features($user, $vendor))->keyBy('key')->all();
}

beforeEach(function () {
    $this->artisan('features:sync');
});

// ── What is listed ───────────────────────────────────────────────────────────

it('lists only the features each person can open, and none an admin has hidden', function () {
    $ctx = engagementVendor();
    $productManager = learnStaff($ctx, 'product_manager');

    $owner = learnFeatures($ctx['owner'], $ctx['vendor']);
    $storekeeper = learnFeatures($ctx['storekeeper'], $ctx['vendor']);
    $manager = learnFeatures($productManager, $ctx['vendor']);

    expect($owner)->toHaveKeys(['customer_debts', 'sales_point', 'expenses', 'team_members'])
        ->and($storekeeper)->toHaveKeys(['customer_debts', 'sales_point'])
        ->and($storekeeper)->not->toHaveKey('settlement_statement')
        // A product manager can't open debts or the till, so isn't taught them.
        ->and($manager)->not->toHaveKeys(['customer_debts', 'sales_point'])
        ->and($manager)->toHaveKey('product_import');

    // Every listed feature passes that feature's own access check.
    foreach ([[$ctx['storekeeper'], $storekeeper], [$productManager, $manager]] as [$user, $list]) {
        actInTipsPanel($user, $ctx['vendor']);
        foreach (array_keys($list) as $key) {
            expect(app(FeatureRegistry::class)->find($key)->canAccess($user, $ctx['vendor']))->toBeTrue("{$key} listed without access");
        }
    }

    AppFeature::where('key', 'customer_debts')->update(['is_visible' => false]);
    learnFreshRequest();

    expect(learnFeatures($ctx['owner'], $ctx['vendor']))->not->toHaveKey('customer_debts')
        ->toHaveKeys(['sales_point', 'expenses']);
});

it('renders the page with the admin’s copy, in the admin’s order', function () {
    $ctx = engagementVendor();
    AppFeature::where('key', 'customer_debts')->update(['title' => 'Sell on credit, safely', 'sort_order' => -1]);
    actInTipsPanel($ctx['owner'], $ctx['vendor']);

    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertOk()
        ->assertSee('Learn GadgetPlug')
        ->assertSee('Sell on credit, safely')
        ->assertSee('Show me');

    expect(array_key_first(learnFeatures($ctx['owner'], $ctx['vendor'])))->toBe('customer_debts');
});

// ── Ticks and progress ───────────────────────────────────────────────────────

it('ticks exactly what firstUsedAt says: the business for the owner, their own use for staff', function () {
    $ctx = engagementVendor();
    $colleague = learnStaff($ctx, 'storekeeper');
    $debt = recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(2));
    $feature = app(FeatureRegistry::class)->find('customer_debts');

    $owner = learnFeatures($ctx['owner'], $ctx['vendor'])['customer_debts'];
    $keeper = learnFeatures($ctx['storekeeper'], $ctx['vendor'])['customer_debts'];
    $other = learnFeatures($colleague, $ctx['vendor'])['customer_debts'];

    expect($owner['usedAt']?->toIso8601String())->toBe($feature->firstUsedAt($ctx['vendor']->id, null)->toIso8601String())
        ->and($owner['businessWide'])->toBeTrue()
        ->and($keeper['usedAt']?->toIso8601String())->toBe($feature->firstUsedAt($ctx['vendor']->id, $ctx['storekeeper']->id)->toIso8601String())
        ->and($keeper['businessWide'])->toBeFalse()
        // The colleague hasn't recorded a debt, so no tick for them.
        ->and($other['usedAt'])->toBeNull()
        ->and($feature->firstUsedAt($ctx['vendor']->id, $colleague->id))->toBeNull()
        ->and($debt->exists)->toBeTrue();
});

it('counts progress as the ticked share of the listed features', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $catalogue = app(LearnCatalogue::class);
    $before = $catalogue->progress($ctx['owner'], $ctx['vendor']);

    recordTestDebt($ctx, $ctx['owner']);
    learnFreshRequest();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $features = app(LearnCatalogue::class)->features($ctx['owner'], $ctx['vendor']);
    $after = app(LearnCatalogue::class)->progress($ctx['owner'], $ctx['vendor']);

    expect($after['total'])->toBe(count($features))
        ->and($after['used'])->toBe(count(array_filter($features, fn ($f) => $f['usedAt'] !== null)))
        ->and($after['used'])->toBe($before['used'] + 1)
        ->and($after['percent'])->toBe((int) floor(100 * $after['used'] / $after['total']));

    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertSeeInOrder(['You’ve used', (string) $after['used'], "of {$after['total']} features"], false);
});

it('labels honestly: owners are told ticks count the business, staff see which cards are whole-business', function () {
    $ctx = engagementVendor();

    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertSee('Ticks count anyone at')
        ->assertDontSee('Whole business');

    // A store admin can open Team Members, whose table doesn't record who did it.
    $admin = learnStaff($ctx, 'store_admin');
    expect(learnFeatures($admin, $ctx['vendor'])['team_members']['businessWide'])->toBeTrue();

    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertSee('Ticks show what you’ve done yourself')
        ->assertSee('Whole business');
});

// ── Actions on the page ──────────────────────────────────────────────────────

it('"Show me" goes to the feature’s page, recorded as a tap', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $url = app(FeatureRegistry::class)->find('customer_debts')->destinationUrl($ctx['vendor']);

    Livewire::test(LearnGadgetPlug::class)
        ->call('showMe', 'customer_debts')
        ->assertRedirect($url);

    expect(TipEvent::where('feature_key', 'customer_debts')->whereNull('tip_id')->sole())
        ->event->toBe(TipEvent::CLICKED)
        ->route->toBe(LearnCatalogue::ROUTE);
});

it('refuses "Show me" and thumbs for a feature the person isn’t shown', function () {
    $ctx = engagementVendor();
    actInTipsPanel(learnStaff($ctx, 'product_manager'), $ctx['vendor']);

    Livewire::test(LearnGadgetPlug::class)
        ->call('showMe', 'customer_debts')
        ->assertNoRedirect()
        ->call('rateFeature', 'customer_debts', 'yes')
        ->call('featurePlayed', 'nonsense');

    expect(TipEvent::count())->toBe(0);
});

it('records thumbs on a feature, the latest answer counting, and a video play once per visit', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);

    Livewire::test(LearnGadgetPlug::class)
        ->call('rateFeature', 'expenses', 'yes')
        ->call('rateFeature', 'expenses', 'no')
        ->call('featurePlayed', 'expenses')
        ->call('featurePlayed', 'expenses');

    expect(TipFeedback::forFeature('expenses'))->toMatchArray(['yes' => 0, 'no' => 1, 'plays' => 1]);

    learnFreshRequest();
    expect(learnFeatures($ctx['owner'], $ctx['vendor'])['expenses']['answer'])->toBe('no');
});

// ── What's new ───────────────────────────────────────────────────────────────

it('shows every announcement sent to this person, with an unread dot until they have seen it', function () {
    $ctx = engagementVendor();
    $seenAsPopup = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'title' => 'Ask GadgetPlug is here', 'starts_at' => now()->subDays(3)]);
    $unseen = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'title' => 'Stock counts on your phone', 'starts_at' => now()->subDay()]);
    makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'title' => 'Owners only', 'roles' => [Tip::ROLE_OWNER]]);
    makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'title' => 'Still a draft', 'status' => Tip::STATUS_DRAFT]);
    makeTip(['title' => 'An ordinary tip']);

    logTipEvent($seenAsPopup, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN);

    actInTipsPanel($ctx['storekeeper'], $ctx['vendor']);
    $list = collect(app(LearnCatalogue::class)->announcements($ctx['storekeeper'], $ctx['vendor']));

    expect($list->pluck('card.title')->all())->toBe(['Stock counts on your phone', 'Ask GadgetPlug is here'])
        ->and($list->firstWhere('tip.id', $unseen->id)['read'])->toBeFalse()
        ->and($list->firstWhere('tip.id', $seenAsPopup->id)['read'])->toBeTrue()
        ->and(app(LearnCatalogue::class)->unreadCount($ctx['storekeeper'], $ctx['vendor']))->toBe(1);

    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertSee('What’s new')
        ->assertSee('1 new')
        ->assertDontSee('Owners only')
        ->assertDontSee('Still a draft')
        ->assertDontSee('An ordinary tip');
});

it('clears the unread dot once read on the Learn page, without using up the day’s pop-up', function () {
    $ctx = engagementVendor();
    $announcement = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'title' => 'Ask GadgetPlug is here']);
    actInTipsPanel($ctx['owner'], $ctx['vendor']);

    Livewire::test(LearnGadgetPlug::class)
        ->call('markRead', $announcement->id)
        ->call('markRead', $announcement->id);

    expect(TipEvent::where('tip_id', $announcement->id)->where('event', TipEvent::SHOWN)->count())->toBe(1)
        ->and(app(LearnCatalogue::class)->unreadCount($ctx['owner'], $ctx['vendor']))->toBe(0)
        // Read here counts as seen: it won't also pop up.
        ->and(app(TipEligibility::class)->explain($announcement, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('retired')
        // A Learn-page read is not a pop-up, so today's slot is still free.
        ->and(app(TipEligibility::class)->shownToday($ctx['owner']))->toBe(0);

    $other = makeTip(['title' => 'An ordinary tip']);
    expect(app(TipEligibility::class)->pick(tipsContext($ctx['owner'], $ctx['vendor']))?->tip->id)->toBe($other->id);
});

it('never marks an announcement read that was not sent to this person', function () {
    $ctx = engagementVendor();
    $ownersOnly = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'roles' => [Tip::ROLE_OWNER]]);
    actInTipsPanel($ctx['storekeeper'], $ctx['vendor']);

    Livewire::test(LearnGadgetPlug::class)
        ->call('markRead', $ownersOnly->id)
        ->call('rateAnnouncement', $ownersOnly->id, 'yes');

    expect(TipEvent::count())->toBe(0);
});

it('never shows a pop-up on the Learn page itself', function () {
    $ctx = engagementVendor();
    makeTip();

    expect(app(TipEligibility::class)->pick(tipsContext($ctx['owner'], $ctx['vendor'], LearnCatalogue::ROUTE)))->toBeNull()
        ->and(app(TipEligibility::class)->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->not->toBeNull();
});

// ── Who can open it ──────────────────────────────────────────────────────────

it('is gated by view_learn_page, which every default role holds and a vendor can take away', function () {
    $ctx = engagementVendor();

    foreach (['store_admin', 'product_manager', 'order_manager', 'inventory_manager', 'storekeeper', 'member'] as $role) {
        actInTipsPanel(learnStaff($ctx, $role), $ctx['vendor']);
        expect(LearnGadgetPlug::canAccess())->toBeTrue("{$role} can't open Learn");
    }

    Role::where('name', 'storekeeper')->where('team_id', $ctx['vendor']->id)->first()->revokePermissionTo('view_learn_page');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ctx['storekeeper']->unsetRelation('roles')->unsetRelation('permissions');

    actInTipsPanel($ctx['storekeeper'], $ctx['vendor']);
    expect(LearnGadgetPlug::canAccess())->toBeFalse()
        ->and(LearnGadgetPlug::shouldRegisterNavigation())->toBeFalse();
    expect($this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))->status())->not->toBe(200);
});

it('is not part of the free plan', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Free shop '.uniqid(), 'plan' => Vendor::PLAN_FREE, 'status' => Vendor::STATUS_APPROVED]);
    actInTipsPanel($owner, $vendor);

    expect(LearnGadgetPlug::canAccess())->toBeFalse();
    expect($this->get(LearnGadgetPlug::getUrl(tenant: $vendor))->status())->not->toBe(200);
});

// ── The dashboard card ───────────────────────────────────────────────────────

it('shows progress on the dashboard until dismissed, and stays dismissed', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $progress = app(LearnCatalogue::class)->progress($ctx['owner'], $ctx['vendor']);

    Livewire::withoutLazyLoading()->test(LearnProgressCard::class)
        ->assertSee("of {$progress['total']} features")
        ->assertSee(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->call('dismiss')
        ->assertDontSee('features');

    expect(app(LearnCatalogue::class)->cardDismissed($ctx['owner'], $ctx['vendor']))->toBeTrue();

    Livewire::withoutLazyLoading()->test(LearnProgressCard::class)->assertDontSee('features');

    // Dismissing at one business doesn't hide it for the storekeeper.
    actInTipsPanel($ctx['storekeeper'], $ctx['vendor']);
    Livewire::withoutLazyLoading()->test(LearnProgressCard::class)->assertSee('features');
});

it('puts the card on the vendor dashboard only', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);

    $this->get(Dashboard::getUrl(tenant: $ctx['vendor']))
        ->assertOk()
        ->assertSeeLivewire(LearnProgressCard::class);

    $this->get(LearnGadgetPlug::getUrl(tenant: $ctx['vendor']))
        ->assertDontSeeLivewire(LearnProgressCard::class);
});

// ── Free plan: tips never point outside it ──────────────────────────────────

it('agrees with the free plan itself about every page a tip can send a free shop to', function () {
    // The tips read EnforceFreePlan::ALLOWED_ROUTES directly, so there is one
    // list. This proves the behaviour too: every destination an admin can pick
    // is requested for real, through the free-plan middleware, and the tips'
    // answer must match what happens.
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Free shop '.uniqid(), 'plan' => Vendor::PLAN_FREE, 'status' => Vendor::STATUS_APPROVED]);
    actInTipsPanel($owner, $vendor);

    $resolver = app(DestinationResolver::class);
    $routes = collect(app(PanelDestinations::class)->options())
        ->except('Admin panel')
        ->flatMap(fn (array $pages) => array_keys($pages));

    expect($routes)->toContain('filament.vendor.resources.products.index')->toContain('pos.vendor');

    $opened = [];

    foreach ($routes as $route) {
        $url = $resolver->url($route, [], $vendor);
        $opens = $this->get($url)->status() === 200;

        expect($resolver->canAccess($route, $owner, $vendor))->toBe($opens, "{$route}: tips and the free plan disagree");

        if ($opens) {
            $opened[] = $route;
        }
    }

    // Something must actually open, or the test proves nothing.
    expect($opened)->toContain('filament.vendor.resources.products.index')
        ->not->toContain('pos.vendor');
});

// ── Roles: Learn is on by default, and survives a save ───────────────────────

it('ticks Learn on a new role a vendor creates, and lets them untick it', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $learn = Permission::where('name', 'view_learn_page')->value('id');

    // Owners reach the Roles screen once the platform allows it for them.
    $ctx['vendor']->update(['owner_can_manage_roles' => true]);
    Filament\Facades\Filament::setTenant($ctx['vendor']->fresh());
    Filament\Facades\Filament::bootCurrentPanel();

    Livewire::test(CreateRole::class)
        ->assertSet('data.permissions', fn ($ids) => in_array($learn, (array) $ids, false))
        ->fillForm(['name' => 'Weekend cashier'])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'No learning', 'permissions' => []])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = fn (string $name) => Role::where('name', $name)->where('team_id', $ctx['vendor']->id)->sole();

    expect($role('Weekend cashier')->hasPermissionTo('view_learn_page'))->toBeTrue()
        ->and($role('No learning')->hasPermissionTo('view_learn_page'))->toBeFalse();
});

it('keeps Learn on a default role when the vendor saves it on the Roles screen', function () {
    $ctx = engagementVendor();
    actInTipsPanel($ctx['owner'], $ctx['vendor']);
    $storekeeper = Role::where('name', 'storekeeper')->where('team_id', $ctx['vendor']->id)->sole();
    // Owners reach the Roles screen once the platform allows it for them.
    $ctx['vendor']->update(['owner_can_manage_roles' => true]);
    Filament\Facades\Filament::setTenant($ctx['vendor']->fresh());
    Filament\Facades\Filament::bootCurrentPanel();

    Livewire::test(EditRole::class, ['record' => $storekeeper->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($storekeeper->fresh()->hasPermissionTo('view_learn_page'))->toBeTrue();
});

it('never offers a free-plan shop a tip for a page outside its plan', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Free shop '.uniqid(), 'plan' => Vendor::PLAN_FREE, 'status' => Vendor::STATUS_APPROVED]);
    $debts = makeTip(['feature_key' => 'customer_debts', 'button_label' => 'See who owes you', 'priority' => 10]);
    $till = makeTip(['button_label' => 'Open Sales Point', 'button_route' => 'pos.vendor', 'priority' => 9]);
    $products = makeTip(['button_label' => 'Your products', 'button_route' => 'filament.vendor.resources.products.index']);

    $context = tipsContext($owner, $vendor);
    $eligibility = app(TipEligibility::class);

    expect($eligibility->explain($debts, $context))->toBe('no_feature_access')
        ->and($eligibility->explain($till, $context))->toBe('no_destination_access')
        ->and($eligibility->pick($context)?->tip->id)->toBe($products->id);
});
