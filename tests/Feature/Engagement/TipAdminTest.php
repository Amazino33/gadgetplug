<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\TipSettingsPage;
use App\Filament\Resources\AppFeatures\AppFeatureResource;
use App\Filament\Resources\AppFeatures\Pages\EditAppFeature;
use App\Filament\Resources\Tips\Pages\CreateTip;
use App\Filament\Resources\Tips\Pages\EditTip;
use App\Filament\Resources\Tips\Pages\ListTips;
use App\Filament\Resources\Tips\TipResource;
use App\Models\AppFeature;
use App\Models\Tip;
use App\Models\TipSetting;
use App\Models\User;
use App\Support\Engagement\PanelDestinations;
use App\Support\Engagement\TipMedia;
use Database\Seeders\EngagementTipsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function tipsSuperAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

/** Platform staff who write tips: the permission, and nothing else. */
function tipWriter(): User
{
    Permission::firstOrCreate(['name' => 'manage_in_app_tips', 'guard_name' => 'web']);
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->givePermissionTo('manage_in_app_tips');

    return $user;
}

function actAsTipAdmin(?User $user = null): User
{
    $user ??= tipsSuperAdmin();
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $user;
}

// ── Who gets in ──────────────────────────────────────────────────────────────

it('lets super admins and holders of manage_in_app_tips in, and nobody else', function () {
    $screens = [TipResource::class, AppFeatureResource::class, TipSettingsPage::class];

    actAsTipAdmin();
    foreach ($screens as $screen) {
        expect($screen::canAccess())->toBeTrue("super admin locked out of {$screen}");
    }

    $writer = actAsTipAdmin(tipWriter());
    foreach ($screens as $screen) {
        expect($screen::canAccess())->toBeTrue("tip writer locked out of {$screen}");
    }

    // A writer gets into the admin panel, lands on In-App Tips, and opens it.
    expect($writer->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    $this->get('/admin')->assertRedirect('/admin/tips');
    $this->get('/admin/tips')->assertOk();

    // A vendor owner with every vendor permission still cannot write tips.
    $ctx = engagementVendor();
    test()->actingAs($ctx['owner']);
    foreach ($screens as $screen) {
        expect($screen::canAccess())->toBeFalse("vendor owner let into {$screen}")
            ->and($screen::shouldRegisterNavigation())->toBeFalse();
    }
});

it('lets someone holding ONLY manage_in_app_tips reach the three tip screens and nothing else in the admin panel', function () {
    $writer = actAsTipAdmin(tipWriter());
    Filament::bootCurrentPanel();
    $panel = Filament::getPanel('admin');
    $allowed = [TipResource::class, AppFeatureResource::class];

    foreach ($panel->getResources() as $resource) {
        $expected = in_array($resource, $allowed, true);

        expect($resource::canAccess())->toBe($expected, "{$resource}::canAccess() should be ".($expected ? 'true' : 'false'));

        if ($expected) {
            continue;
        }

        // The policy itself refuses, not just the menu: every ability a list
        // or form would ask for.
        $model = $resource::getModel();
        if (Gate::getPolicyFor($model) !== null) {
            foreach (['viewAny', 'create'] as $ability) {
                expect(Gate::forUser($writer)->allows($ability, $model))
                    ->toBeFalse("{$model} policy allows {$ability} to a tip writer");
            }
        }

        // And the address typed straight in is refused.
        if (array_key_exists('index', $resource::getPages())) {
            test()->getJson($resource::getUrl('index', panel: 'admin'))->assertForbidden();
        }
    }

    foreach ($panel->getPages() as $page) {
        if ($page === Dashboard::class) {
            // The dashboard only sends a writer on to In-App Tips.
            expect($page::shouldRegisterNavigation())->toBeFalse();

            continue;
        }

        $expected = $page === TipSettingsPage::class;

        expect($page::canAccess())->toBe($expected, "{$page}::canAccess() should be ".($expected ? 'true' : 'false'));

        if (! $expected) {
            test()->getJson($page::getUrl(panel: 'admin'))->assertForbidden();
        }
    }

    expect(Dashboard::homeFor($writer))->toBe(TipResource::getUrl('index', panel: 'admin'));
    test()->get(TipResource::getUrl('index', panel: 'admin'))->assertOk();
    test()->get(AppFeatureResource::getUrl('index', panel: 'admin'))->assertOk();
    test()->get(TipSettingsPage::getUrl(panel: 'admin'))->assertOk();
});

it('refuses the tip screens by direct URL to a signed-in admin without the permission', function () {
    // A platform user who can reach the admin panel for something else.
    Permission::firstOrCreate(['name' => 'manage_dispatch', 'guard_name' => 'web']);
    $dispatcher = User::factory()->create();
    setPermissionsTeamId(null);
    $dispatcher->givePermissionTo('manage_dispatch');

    $this->actingAs($dispatcher);
    foreach (['/admin/tips', '/admin/tips/create', '/admin/app-features', '/admin/tip-settings'] as $path) {
        // The app turns a 403 into a redirect with a message (never a dead
        // end; see bootstrap/app.php), so refused means: not the page.
        $response = $this->get($path);

        expect($response->status())->not->toBe(200, "{$path} opened for a dispatcher");

        if ($response->isRedirect()) {
            expect($response->headers->get('Location'))->not->toEndWith($path);
        }
    }
});

it('renders every tip screen for a super admin, under Help Centre', function () {
    $this->artisan('features:sync');
    $tip = makeTip();
    $this->actingAs(tipsSuperAdmin());

    foreach (['/admin/tips', '/admin/tips/create', "/admin/tips/{$tip->id}/edit", '/admin/app-features', '/admin/tip-settings'] as $path) {
        $this->get($path)->assertOk();
    }

    expect(TipResource::getNavigationGroup())->toBe('Help Centre')
        ->and(AppFeatureResource::getNavigationGroup())->toBe('Help Centre')
        ->and(TipSettingsPage::getNavigationGroup())->toBe('Help Centre');
});

// ── Writing a tip ────────────────────────────────────────────────────────────

it('creates a tip whose button follows the linked feature', function () {
    $admin = actAsTipAdmin();

    Livewire::test(CreateTip::class)
        ->fillForm([
            'name' => 'Debts',
            'type' => Tip::TYPE_TIP,
            'feature_key' => 'customer_debts',
            'title' => 'Did you know customers can buy on credit?',
            'message' => '<p>Choose <strong>Debt (Owing)</strong> at checkout.</p>',
            'button_label' => 'See who owes you',
            'button_target' => 'feature',
            'panels' => ['vendor'],
            'condition' => 'always',
            'status' => Tip::STATUS_DRAFT,
            'priority' => 0,
            'delay_seconds' => 4,
            'frequency' => Tip::FREQUENCY_ONCE,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tip::sole())
        ->feature_key->toBe('customer_debts')
        ->button_route->toBeNull()
        ->button_url->toBeNull()
        ->created_by->toBe($admin->id)
        ->display_style->toBe(Tip::STYLE_SHEET);
});

it('keeps only the destination the admin chose', function () {
    actAsTipAdmin();
    $tip = makeTip(['button_label' => 'Go', 'button_url' => 'https://example.com/old']);

    Livewire::test(EditTip::class, ['record' => $tip->getRouteKey()])
        ->assertFormSet(['button_target' => 'url'])
        ->fillForm(['button_target' => 'page', 'button_route' => 'filament.vendor.resources.customer-debts.index'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tip->fresh())
        ->button_route->toBe('filament.vendor.resources.customer-debts.index')
        ->button_url->toBeNull();
});

it('refuses a button with nowhere to go, a page that is not a real panel page, or an unsafe link', function () {
    actAsTipAdmin();
    $tip = makeTip(['button_label' => 'Go']);

    Livewire::test(EditTip::class, ['record' => $tip->getRouteKey()])
        ->fillForm(['feature_key' => null, 'button_target' => 'feature'])
        ->call('save')
        ->assertHasFormErrors(['button_target']);

    Livewire::test(EditTip::class, ['record' => $tip->getRouteKey()])
        ->fillForm(['button_target' => 'page', 'button_route' => 'filament.vendor.resources.products.create'])
        ->call('save')
        ->assertHasFormErrors(['button_route']);

    Livewire::test(EditTip::class, ['record' => $tip->getRouteKey()])
        ->fillForm(['button_target' => 'url', 'button_url' => 'javascript:alert(1)'])
        ->call('save')
        ->assertHasFormErrors(['button_url']);
});

it('offers list pages and Sales Point as destinations, never forms or single records', function () {
    actAsTipAdmin();
    $routes = collect(app(PanelDestinations::class)->options())->flatMap(fn ($pages) => array_keys($pages));

    expect($routes)->toContain('filament.vendor.resources.customer-debts.index')
        ->toContain('filament.vendor.resources.cash-submissions.index')
        ->toContain('pos.vendor')
        ->not->toContain('filament.vendor.resources.products.create')
        ->not->toContain('filament.vendor.resources.customer-debts.view');
});

it('duplicates a tip as a draft and pauses or activates one', function () {
    actAsTipAdmin();
    $tip = makeTip(['name' => 'Original']);

    Livewire::test(ListTips::class)
        ->callTableAction('replicate', $tip)
        ->callTableAction('toggleStatus', $tip);

    expect(Tip::where('name', 'Original (copy)')->sole()->status)->toBe(Tip::STATUS_DRAFT)
        ->and($tip->fresh()->status)->toBe(Tip::STATUS_PAUSED);
});

it('sizes videos for mobile data', function () {
    expect(TipMedia::videoProblem('video/mp4', 5 * 1024 * 1024))->toBeNull()
        ->and(TipMedia::videoProblem('video/mp4', 5 * 1024 * 1024 + 1))->toContain('5 MB')
        ->and(TipMedia::videoProblem('image/gif', 3 * 1024 * 1024))->toBeNull()
        ->and(TipMedia::videoProblem('image/gif', 3 * 1024 * 1024 + 1))->toContain('3 MB')
        ->and(TipMedia::videoProblem('video/quicktime', 10))->not->toBeNull();
});

// ── Features and settings ────────────────────────────────────────────────────

it('edits feature copy but never creates or deletes features by hand', function () {
    $this->artisan('features:sync');
    $user = actAsTipAdmin(tipWriter());
    $feature = AppFeature::where('key', 'customer_debts')->first();

    Livewire::test(EditAppFeature::class, ['record' => $feature->getRouteKey()])
        ->fillForm(['title' => 'Sell on credit, safely', 'is_visible' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($feature->fresh())->title->toBe('Sell on credit, safely')->is_visible->toBeFalse()
        ->and(AppFeatureResource::hasPage('create'))->toBeFalse()
        ->and($user->can('create', AppFeature::class))->toBeFalse()
        ->and($user->can('delete', $feature))->toBeFalse();
});

it('saves the platform defaults', function () {
    actAsTipAdmin();

    Livewire::test(TipSettingsPage::class)
        ->fillForm(['daily_cap' => 2, 'cooldown_days' => 5, 'adoption_window_days' => 10])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TipSetting::current())->daily_cap->toBe(2)->cooldown_days->toBe(5)->adoption_window_days->toBe(10);
});

// ── Seeds and styling ────────────────────────────────────────────────────────

it('seeds the starter tips as drafts, with the agreed debt tip copy', function () {
    $this->seed(EngagementTipsSeeder::class);
    $this->seed(EngagementTipsSeeder::class); // safe to run twice

    expect(Tip::count())->toBe(5)
        ->and(Tip::where('status', '!=', Tip::STATUS_DRAFT)->count())->toBe(0)
        ->and(Tip::where('name', 'Debts: buy on credit')->sole())
        ->title->toBe('Did you know customers can buy on credit?')
        ->message->toBe('Choose Debt (Owing) at checkout in Sales Point, then see exactly who owes you here.')
        ->button_label->toBe('See who owes you')
        ->feature_key->toBe('customer_debts')
        ->and(Tip::where('moment', 'debtors_page_empty_visit')->sole()->button_route)->toBe('pos.vendor')
        ->and(Tip::where('type', Tip::TYPE_ANNOUNCEMENT)->sole()->display_style)->toBe(Tip::STYLE_MODAL);
});

it('ships the tip styles and brand tokens in both built panel themes', function (string $theme) {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest[$theme]['file']));

    foreach (['--gp-brand-green', '--gp-brand-orange', '.gp-tip-card', '.gp-tip-primary'] as $needle) {
        expect(str_contains($css, $needle))->toBeTrue("{$needle} missing from the {$theme} build");
    }

    // Decision 7: Inter only, no Montserrat download.
    expect(str_contains($css, 'Montserrat'))->toBeFalse();
})->with([
    'vendor' => 'resources/css/filament/vendor/theme.css',
    'admin' => 'resources/css/filament/admin/theme.css',
]);
