<?php

use App\Actions\Cash\GenerateSettlementStatementAction;
use App\Filament\Vendor\Pages\StoreSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * The settlement carries revenue, cost of goods, margin and the branch's whole
 * standing position. Being on the team is not a reason to see any of it.
 *
 * Gating the actions was not enough — the page itself and the statement URLs
 * each need their own guard, or somebody simply opens the page, or holds a link.
 */
function accessContext(): array
{
    $ctx = handoffContext();

    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['collector']->givePermissionTo('view_store_settlement');

    $ctx['statement'] = app(GenerateSettlementStatementAction::class)->execute(
        generatedBy: $ctx['owner'],
        store:       $ctx['store'],
        from:        now()->subMonth(),
        to:          now()->addDay(),
    );

    return $ctx;
}

function asVendorUser(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);
}

test('a storekeeper cannot open the settlement page', function () {
    $ctx = accessContext();
    asVendorUser($ctx, $ctx['keeper']);

    // She may hand cash over and count a shelf. Neither is a reason to see
    // what the business makes on each sale.
    expect(StoreSettlement::canAccess())->toBeFalse()
        ->and(StoreSettlement::shouldRegisterNavigation())->toBeFalse();
});

test('the owner can open it', function () {
    $ctx = accessContext();
    asVendorUser($ctx, $ctx['owner']);

    expect(StoreSettlement::canAccess())->toBeTrue();
});

test('somebody granted the permission can open it', function () {
    $ctx = accessContext();
    asVendorUser($ctx, $ctx['collector']);

    expect(StoreSettlement::canAccess())->toBeTrue();
});

test('a storekeeper cannot read a statement even with the link', function () {
    $ctx = accessContext();

    // The page guard is worth nothing if the URL behind it is open.
    $response = $this->actingAs($ctx['keeper'])
        ->get(route('settlement.show', $ctx['statement']));

    expect($response->isOk())->toBeFalse();
    $response->assertDontSee('Cost of goods sold')
        ->assertDontSee($ctx['statement']->reference);
});

test('a storekeeper cannot download the pdf either', function () {
    $ctx = accessContext();

    $response = $this->actingAs($ctx['keeper'])
        ->get(route('settlement.pdf', $ctx['statement']));

    expect($response->isOk())->toBeFalse();
});

test('a permitted user can read the statement and its pdf', function () {
    $ctx = accessContext();

    $this->actingAs($ctx['collector'])
        ->get(route('settlement.show', $ctx['statement']))
        ->assertOk()
        ->assertSee($ctx['statement']->reference);

    $this->actingAs($ctx['collector'])
        ->get(route('settlement.pdf', $ctx['statement']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('somebody from another business cannot read it at all', function () {
    $ctx = accessContext();

    $outsider = User::factory()->create();
    App\Models\Vendor::create(['user_id' => $outsider->id, 'name' => 'Rival Ltd '.uniqid()]);

    $response = $this->actingAs($outsider)->get(route('settlement.show', $ctx['statement']));

    expect($response->isOk())->toBeFalse();
    $response->assertDontSee($ctx['statement']->reference);
});

test('the storekeeper role is not granted settlement visibility by default', function () {
    $ctx = handoffContext();

    setPermissionsTeamId($ctx['vendor']->id);

    // The default roles have to get this right on their own — most vendors will
    // never revisit them.
    expect(Spatie\Permission\Models\Role::where('name', 'storekeeper')
        ->where('team_id', $ctx['vendor']->id)->first()
        ->hasPermissionTo('view_store_settlement'))->toBeFalse();

    expect(Spatie\Permission\Models\Role::where('name', 'store_admin')
        ->where('team_id', $ctx['vendor']->id)->first()
        ->hasPermissionTo('view_store_settlement'))->toBeTrue();
});
