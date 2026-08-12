<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Filament\Vendor\Resources\CountSessions\Pages\ListCountSessions;
use App\Filament\Vendor\Resources\CountSessions\Pages\ViewCountSession;
use App\Models\AuditSession;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\InventoryShortageCase;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function countSessionContext(int $stock = 10): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create([
        'user_id'                      => $owner->id,
        'name'                         => 'Leisure Hub',
        'pos_blind_count_participants' => 1,
    ]);
    $vendor->users()->attach($staff->id);

    VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => $stock,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_online'    => true,
        'show_in_pos'    => true,
    ]);

    return compact('owner', 'staff', 'vendor', 'product');
}

function enterPanel(Vendor $vendor): void
{
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

/** Drives a real solo count through the page, the way a storekeeper would. */
function runSoloCount(array $c, int $counted): BlindCountSession
{
    test()->actingAs($c['staff']);
    enterPanel($c['vendor']);

    Livewire::test(BlindCount::class)
        ->call('startSession')
        ->set('count', $counted)
        ->call('submitAll');

    return BlindCountSession::where('vendor_id', $c['vendor']->id)->latest()->firstOrFail();
}

// ── The link ─────────────────────────────────────────────────────────────────

it('files each counted line under the session that produced it', function () {
    $c = countSessionContext();

    $session = runSoloCount($c, 7);

    expect($session->auditLines()->count())->toBe(1)
        ->and($session->auditLines->first()->product_id)->toBe($c['product']->id);
});

it('rolls the session up: variances, shortfall, value and unresolved', function () {
    $c = countSessionContext();

    $session = runSoloCount($c, 7)->fresh();

    expect($session->varianceLines()->count())->toBe(1)
        ->and($session->hasShortfall())->toBeTrue()
        // 3 short at 2,570 cost.
        ->and($session->shortageValueAtCost())->toBe(7710.0)
        // The line is a discrepancy and its case awaits a decision.
        ->and($session->unresolvedCount())->toBe(1);
});

it('shows a balanced count as having nothing outstanding', function () {
    $c = countSessionContext();

    $session = runSoloCount($c, 10)->fresh();

    expect($session->varianceLines()->count())->toBe(0)
        ->and($session->hasShortfall())->toBeFalse()
        ->and($session->shortageValueAtCost())->toBe(0.0)
        ->and($session->unresolvedCount())->toBe(0)
        // A balanced line opens no case.
        ->and(InventoryShortageCase::count())->toBe(0);
});

// ── Stock adjustment ─────────────────────────────────────────────────────────

it('adjusts stock when a manager resolves the counted figure', function () {
    $c = countSessionContext();

    runSoloCount($c, 7);

    $line = AuditSession::where('product_id', $c['product']->id)->firstOrFail();

    // A solo count holds at 'discrepancy' by design — one unverified person's
    // count does not silently move stock. Resolving is what applies it.
    expect($line->status)->toBe('discrepancy')
        ->and($c['product']->fresh()->stock_quantity)->toBe(10);

    app(\App\Actions\Inventory\AdjustStockAction::class)->execute(
        productId: $c['product']->id,
        quantityChanged: 7 - 10,
        transactionType: 'audit_correction',
        userId: $c['owner']->id,
        reference: "Audit #{$line->id}",
    );

    expect($c['product']->fresh()->stock_quantity)->toBe(7);
});

it('leaves the frozen baseline alone when stock moves afterwards', function () {
    $c = countSessionContext();

    $session = runSoloCount($c, 7)->fresh();
    $line    = $session->auditLines->first();

    $c['product']->update(['stock_quantity' => 2]);

    // The count found three missing. Later sales do not enlarge that.
    expect($line->fresh()->countedVariance())->toBe(-3)
        ->and($session->fresh()->shortageValueAtCost())->toBe(7710.0);
});

// ── The screens ──────────────────────────────────────────────────────────────

it('lists the sessions for someone allowed to review counts', function () {
    $c = countSessionContext();
    runSoloCount($c, 7);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ListCountSessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords(BlindCountSession::all());
});

it('opens a session and shows its lines', function () {
    $c = countSessionContext();
    $session = runSoloCount($c, 7);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ViewCountSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSee('SHPLUS 60W Charger')
        ->assertSee('Awaiting decision');
});

it('hides the cost of a variance from staff without the permission', function () {
    $c = countSessionContext();
    runSoloCount($c, 7);

    setPermissionsTeamId($c['vendor']->id);
    $c['staff']->givePermissionTo('view_audit_sessions');

    $this->actingAs($c['staff']);
    enterPanel($c['vendor']);

    Livewire::test(ListCountSessions::class)
        ->assertOk()
        ->assertTableColumnHidden('shortage_value')
        ->assertDontSee('7,710.00');
});

it('shows the cost of a variance to the owner', function () {
    $c = countSessionContext();
    runSoloCount($c, 7);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ListCountSessions::class)
        ->assertOk()
        ->assertTableColumnVisible('shortage_value')
        ->assertSee('7,710.00');
});

it('says so plainly when a session predates the line link', function () {
    $c = countSessionContext();

    // A session from before audit_sessions carried the link.
    $orphan = BlindCountSession::create([
        'vendor_id'        => $c['vendor']->id,
        'storekeeper_a_id' => $c['staff']->id,
        'status'           => 'completed',
        'frequency'        => 'daily',
        'product_order'    => [$c['product']->id],
    ]);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ViewCountSession::class, ['record' => $orphan->getKey()])
        ->assertOk()
        ->assertSee('No lines are linked to this count');
});

// ── The drill-in carries the actions ─────────────────────────────────────────

it('lets the owner resolve a disputed line from inside the count', function () {
    $c = countSessionContext();
    $session = runSoloCount($c, 7);
    $line = $session->auditLines()->firstOrFail();

    expect($line->status)->toBe('discrepancy');

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(
        \App\Filament\Vendor\Resources\CountSessions\RelationManagers\LinesRelationManager::class,
        ['ownerRecord' => $session, 'pageClass' => ViewCountSession::class],
    )
        ->assertOk()
        ->assertCanSeeTableRecords([$line])
        // The same actions as the old flat screen, from one shared definition.
        ->assertTableActionExists('manager_override');
});

it('applies the correction to stock when resolved from the drill-in', function () {
    $c = countSessionContext();
    $session = runSoloCount($c, 7);
    $line = $session->auditLines()->firstOrFail();

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(
        \App\Filament\Vendor\Resources\CountSessions\RelationManagers\LinesRelationManager::class,
        ['ownerRecord' => $session, 'pageClass' => ViewCountSession::class],
    )->callTableAction('manager_override', $line, [
        'manager_override_count' => 7,
        'reason_code'            => 'Suspected Theft',
    ]);

    // Stock corrected, line settled, and a case opened for the decision.
    expect($c['product']->fresh()->stock_quantity)->toBe(7)
        ->and($line->fresh()->status)->toBe('resolved_by_override')
        ->and(InventoryShortageCase::where('count_line_id', $line->id)->exists())->toBeTrue();
});

// ── Unknown is not the same as zero ──────────────────────────────────────────

/** A count from before system_quantity was recorded: counted, flagged, unmeasurable. */
function unmeasurableCount(array $c): BlindCountSession
{
    $session = BlindCountSession::create([
        'vendor_id'        => $c['vendor']->id,
        'storekeeper_a_id' => $c['staff']->id,
        'status'           => 'completed',
        'frequency'        => 'daily',
        'product_order'    => [$c['product']->id],
    ]);

    AuditSession::create([
        'vendor_id'              => $c['vendor']->id,
        'blind_count_session_id' => $session->id,
        'product_id'             => $c['product']->id,
        'storekeeper_a_id'       => $c['staff']->id,
        'count_a'                => 7,
        'system_quantity'        => null,
        'status'                 => 'discrepancy',
    ]);

    return $session->fresh();
}

it('separates "cannot tell" from "nothing missing" on a count with no baseline', function () {
    $c       = countSessionContext();
    $session = unmeasurableCount($c);

    expect($session->unmeasurableLines()->count())->toBe(1)
        ->and($session->isEntirelyUnmeasurable())->toBeTrue()
        // Not counted as a variance — it is unknown, not zero.
        ->and($session->varianceLines()->count())->toBe(0)
        // Yet still awaiting a human, which is what made the old summary read
        // as a contradiction.
        ->and($session->unresolvedCount())->toBe(1);
});

it('says "not measurable" rather than reporting zero variance and zero cost', function () {
    $c       = countSessionContext();
    $session = unmeasurableCount($c);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ViewCountSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSee('Not measurable')
        ->assertSee('Variance cannot be worked out.')
        // The figure that used to be shown as fact.
        ->assertDontSee('₦0.00');
});

it('does not call an unmeasurable line balanced', function () {
    $c       = countSessionContext();
    $session = unmeasurableCount($c);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ViewCountSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSee('No baseline')
        ->assertDontSee('Balanced');
});

it('flags the unmeasurable count in the list instead of showing a zero', function () {
    $c = countSessionContext();
    unmeasurableCount($c);

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ListCountSessions::class)
        ->assertOk()
        ->assertSee('Not measurable');
});

it('still reports a real variance as a number, not as unmeasurable', function () {
    $c       = countSessionContext();
    $session = runSoloCount($c, 7)->fresh();

    expect($session->isEntirelyUnmeasurable())->toBeFalse()
        ->and($session->hasUnmeasurableLines())->toBeFalse();

    $this->actingAs($c['owner']);
    enterPanel($c['vendor']);

    Livewire::test(ViewCountSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertDontSee('Not measurable')
        ->assertSee('7,710.00');
});

it('keeps the old flat line list off the menu', function () {
    expect(\App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource::shouldRegisterNavigation())->toBeFalse();
});
