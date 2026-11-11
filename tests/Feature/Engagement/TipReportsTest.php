<?php

use App\Filament\Pages\FeatureAdoptionPage;
use App\Filament\Resources\Tips\Pages\ReportTip;
use App\Filament\Resources\Tips\TipResource;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\Csv;
use App\Support\Engagement\FeatureAdoption;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\TipAdoption;
use App\Support\Engagement\TipReport;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reportsSuperAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(null);

    return $user;
}

/** Platform staff holding one global permission and nothing else. */
function reportsGlobalUser(string $permission): User
{
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    return $user;
}

/** A debt tip seen by three people: one used it after, one tapped only, one already used it. */
function reportsDebtTip(): array
{
    $ctx = engagementVendor('Report Shop');
    $tapper = User::factory()->create(['name' => '=HYPERLINK("x")']);
    $ctx['vendor']->users()->attach($tapper->id);
    $tip = makeTip(['feature_key' => 'customer_debts', 'button_label' => 'See who owes you']);

    // Storekeeper: saw it at their branch, then recorded their first debt
    // two days later.
    $seen = new TipEvent([
        'tip_id' => $tip->id, 'user_id' => $ctx['storekeeper']->id, 'vendor_id' => $ctx['vendor']->id,
        'store_id' => $ctx['store']->id, 'event' => TipEvent::SHOWN,
    ]);
    $seen->created_at = now()->subDays(5);
    $seen->save();
    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(3));
    // Tapper: saw and tapped, never used it.
    logTipEvent($tip, $tapper, $ctx['vendor'], TipEvent::SHOWN, now()->subDays(4));
    logTipEvent($tip, $tapper, $ctx['vendor'], TipEvent::CLICKED, now()->subDays(4));
    // Owner: judged on the business, which was already using it when they saw it.
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDay());
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::HELPFUL_YES, now()->subDay());

    return [...$ctx, 'tip' => $tip, 'tapper' => $tapper];
}

// ── Per-tip report ───────────────────────────────────────────────────────────

it('reports the funnel, thumbs and one row per person, with TipAdoption’s statuses', function () {
    $c = reportsDebtTip();
    reportsSuperAdmin();

    $summary = app(TipReport::class)->summary($c['tip']);
    $rows = app(TipReport::class)->rows($c['tip'])->keyBy('user');

    expect($summary)->toMatchArray(['saw' => 2, 'tapped' => 1, 'used' => 1, 'already_using' => 1, 'yes' => 1, 'no' => 0, 'percent' => 100])
        ->and($summary)->toMatchArray(app(TipAdoption::class)->summary($c['tip']))
        ->and($rows[$c['storekeeper']->name])->toMatchArray(['status_label' => 'Used it', 'role' => 'Storekeeper', 'scope' => 'This person', 'store' => $c['store']->name])
        ->and($rows['=HYPERLINK("x")']['status_label'])->toBe('Tapped, not used')
        ->and($rows[$c['owner']->name])->toMatchArray(['status_label' => 'Already using', 'role' => 'Owner', 'scope' => 'Business']);

    $this->get(TipResource::getUrl('report', ['record' => $c['tip']]))
        ->assertOk()
        ->assertSee('Did it work?')
        ->assertSee('Tapped, not used')
        ->assertSee($c['storekeeper']->name);
});

it('filters the people list by status and search', function () {
    $c = reportsDebtTip();
    reportsSuperAdmin();

    Livewire::test(ReportTip::class, ['record' => $c['tip']->getRouteKey()])
        ->assertSee($c['owner']->name)
        ->filterTable('status', TipAdoption::USED)
        ->assertSee($c['storekeeper']->name)
        ->assertDontSee($c['owner']->name);

    expect(app(TipReport::class)->filtered($c['tip'], null, 'hyperlink')->pluck('status')->all())->toBe([TipAdoption::TAPPED]);
});

it('downloads the list as CSV, with vendor-typed text never run as a formula', function () {
    $c = reportsDebtTip();
    reportsSuperAdmin();

    $csv = TipReport::csv(app(TipReport::class)->rows($c['tip']));

    expect($csv)->toContain('Business,Branch,Person,Role,Status')
        ->toContain('Used it')
        ->toContain("'=HYPERLINK")
        ->not->toContain(',=HYPERLINK');

    Livewire::test(ReportTip::class, ['record' => $c['tip']->getRouteKey()])
        ->callAction('downloadCsv')
        ->assertFileDownloaded();

    expect(Csv::cell('+2348012345678'))->toBe("'+2348012345678")
        ->and(Csv::cell('Plain'))->toBe('Plain');
});

it('opens the tip report for tip managers only', function () {
    $c = reportsDebtTip();
    $url = TipResource::getUrl('report', ['record' => $c['tip']], panel: 'admin');

    test()->actingAs(reportsGlobalUser('manage_in_app_tips'));
    $this->get($url)->assertOk();

    test()->actingAs(reportsGlobalUser('manage_dispatch'));
    expect($this->get($url)->status())->not->toBe(200);

    test()->actingAs($c['owner']);
    expect($this->get($url)->status())->not->toBe(200);
});

// ── Feature adoption ─────────────────────────────────────────────────────────

/** Three paid shops (one uses debts, staff and owner) and one free shop. */
function adoptionShops(): array
{
    $using = engagementVendor('Uses Debts');
    recordTestDebt($using, $using['storekeeper']);
    recordTestDebt($using, $using['owner']);

    $idle = engagementVendor('Idle Shop');
    $idle['owner']->update(['phone' => '08031112222']);
    $old = engagementVendor('Old Shop');
    $old['vendor']->forceFill(['created_at' => now()->subYear()])->save();

    $freeOwner = User::factory()->create();
    $free = Vendor::create(['user_id' => $freeOwner->id, 'name' => 'Free shop '.uniqid(), 'plan' => Vendor::PLAN_FREE, 'status' => Vendor::STATUS_APPROVED]);

    return compact('using', 'idle', 'old', 'free');
}

it('counts adoption exactly as the registry calculator does, for every feature', function () {
    $s = adoptionShops();
    $adoption = app(FeatureAdoption::class);
    $population = $adoption->population();

    expect($population)->not->toHaveKey($s['free']->id)
        ->and(count($population))->toBe(Vendor::where('plan', '!=', Vendor::PLAN_FREE)->orWhereNull('plan')->count());

    foreach ($adoption->summary() as $row) {
        $feature = app(FeatureRegistry::class)->find($row['key']);
        $expected = collect(array_keys($population))
            ->filter(fn (int $vendorId) => $feature->firstUsedAt($vendorId, null) !== null)
            ->count();

        expect($row['vendors_using'])->toBe($expected, "{$row['key']} vendors")
            ->and($row['vendors_total'])->toBe(count($population));

        if ($feature->supportsUserLevel()) {
            $people = collect($feature->firstUseByUser(array_keys($population)))->sum(fn ($users) => count($users));
            expect($row['users_using'])->toBe($people, "{$row['key']} people");
        } else {
            expect($row['users_using'])->toBeNull()->and($row['business_wide'])->toBeTrue();
        }
    }

    $debts = collect($adoption->summary())->firstWhere('key', 'customer_debts');
    expect($debts)->toMatchArray(['vendors_using' => 1, 'vendors_total' => 3, 'percent' => 33, 'users_using' => 2]);
});

it('lists the businesses not using a feature, with the owner to call, filtered by date joined', function () {
    $s = adoptionShops();
    $adoption = app(FeatureAdoption::class);

    $notUsing = $adoption->notUsing('customer_debts')->keyBy('vendor_id');

    expect($notUsing->keys()->all())->toEqualCanonicalizing([$s['idle']['vendor']->id, $s['old']['vendor']->id])
        ->and($notUsing[$s['idle']['vendor']->id])->toMatchArray([
            'owner' => $s['idle']['owner']->name,
            'phone' => '08031112222',
            'plan' => 'Paid',
        ]);

    $recent = $adoption->notUsing('customer_debts', ['joined_from' => now()->subMonth()->toDateString()]);
    expect($recent->pluck('vendor_id')->all())->toBe([$s['idle']['vendor']->id]);

    $free = $adoption->notUsing('customer_debts', ['plan' => FeatureAdoption::PLAN_FREE]);
    expect($free->pluck('vendor_id')->all())->toBe([$s['free']->id]);
});

it('serves cached figures until refreshed', function () {
    $s = adoptionShops();
    $adoption = app(FeatureAdoption::class);
    $usingBefore = collect($adoption->summary())->firstWhere('key', 'customer_debts')['vendors_using'];

    recordTestDebt($s['idle'], $s['idle']['owner']);
    expect(collect($adoption->summary())->firstWhere('key', 'customer_debts')['vendors_using'])->toBe($usingBefore);

    $adoption->refresh();
    expect(collect($adoption->summary())->firstWhere('key', 'customer_debts')['vendors_using'])->toBe($usingBefore + 1);
});

it('renders the adoption page, its drill-down and its CSV for a super admin', function () {
    $s = adoptionShops();
    reportsSuperAdmin();

    $this->get(FeatureAdoptionPage::getUrl())
        ->assertOk()
        ->assertSee('Feature Adoption')
        ->assertSee('33%');

    $this->get(FeatureAdoptionPage::getUrl(['feature' => 'customer_debts']))
        ->assertOk()
        ->assertSee($s['idle']['vendor']->name)
        ->assertSee('08031112222')
        ->assertDontSee($s['using']['vendor']->name);

    Livewire::withQueryParams(['feature' => 'customer_debts'])
        ->test(FeatureAdoptionPage::class)
        ->callAction('downloadCsv')
        ->assertFileDownloaded();

    expect(FeatureAdoptionPage::getNavigationGroup())->toBe('Help Centre');
});

it('opens Feature Adoption for super admins and view_feature_adoption holders only', function () {
    adoptionShops();

    $viewer = reportsGlobalUser('view_feature_adoption');
    test()->actingAs($viewer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(FeatureAdoptionPage::canAccess())->toBeTrue()
        ->and($viewer->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    $this->get(FeatureAdoptionPage::getUrl(panel: 'admin'))->assertOk();
    // It holds owner phone numbers: writing tips alone doesn't open it.
    foreach (['manage_in_app_tips', 'manage_dispatch'] as $permission) {
        test()->actingAs(reportsGlobalUser($permission));
        expect(FeatureAdoptionPage::canAccess())->toBeFalse("{$permission} opened adoption");
        test()->getJson(FeatureAdoptionPage::getUrl(panel: 'admin'))->assertForbidden();
    }

    $ctx = engagementVendor();
    test()->actingAs($ctx['owner']);
    expect(FeatureAdoptionPage::canAccess())->toBeFalse();
});
