<?php

use App\Filament\Vendor\Pages\AccountClose;
use App\Models\Store;
use App\Models\StoreAccountClose;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/CloseHelpers.php';

uses(RefreshDatabase::class);

/** Sign in to the vendor panel, with permissions seeded as production has them. */
function closePanel(array $ctx, ?User $user = null): void
{
    cashRoles($ctx['vendor']);
    test()->actingAs($user ?? $ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);
}

/** Somebody on the team holding exactly the permissions named, and no more. */
function closeStaff(array $ctx, array $permissions): User
{
    cashRoles($ctx['vendor']);
    $user = User::factory()->create();
    $user->stores()->attach($ctx['store']->id);
    $ctx['vendor']->users()->syncWithoutDetaching([$user->id]);
    setPermissionsTeamId($ctx['vendor']->id);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function closeScreen(array $ctx, array $filters = [])
{
    return Livewire::test(AccountClose::class)
        ->set('filters', array_merge([
            'store_id' => $ctx['store']->id,
            'from'     => now()->subMonth()->toDateString(),
            'to'       => now()->toDateString(),
        ], $filters));
}

test('the screen shows both sides of the balance for a branch', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    closeScreen($ctx)
        ->assertOk()
        ->assertSee('Value sold')
        ->assertSee('Where that value went')
        ->assertSee('Cash handed over')
        ->assertSee('Left on credit')
        // The gap, and the reason it is only ever cash.
        ->assertSee('₦30,000.00');
});

test('the screen never shows profit or cost of goods', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);

    // A locked rule. Money reconciliation asks where the cash went; profit asks
    // what the goods cost, and a page carrying both is a page where a shortage
    // gets argued about in margin.
    closeScreen($ctx)
        ->assertOk()
        ->assertDontSee('Cost of goods')
        ->assertDontSee('Profit')
        ->assertDontSee('Margin');
});

test('without a closing count the screen says only the money has been checked', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeScreen($ctx)
        ->assertOk()
        ->assertSee('No closing count selected');
});

test('stock received is shown whether or not a count has been taken', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeMovement($ctx, 'restock', 12);

    // Asked of every branch, count or no count: somebody who has just taken a
    // delivery and sees nothing about it concludes the screen lost it.
    closeScreen($ctx)
        ->assertOk()
        ->assertSee('Received this period')
        ->assertSee('12 units')
        // And why it is not money on either side above.
        ->assertSee('Units, not money');
});

test('the period being closed and what came into it sit beside the count pickers', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeMovement($ctx, 'restock', 12);

    // Somebody choosing a count is deciding whether it matches the period in
    // front of them, so the period has to be there — not only further down the
    // page. Deliveries for the same reason: a branch that took stock in and
    // sees nothing about it concludes the screen lost it.
    //
    // The option labels themselves are not asserted here: these are searchable
    // selects, so Filament fetches their options over AJAX rather than putting
    // them in the first render.
    closeScreen($ctx)
        ->assertOk()
        ->assertSee('12 units came in on 0 deliveries in this period')
        ->assertSee('Counting the shelf is the only check');
});

test('the variance renders once a closing count is chosen', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 7);

    closeScreen($ctx, [
        'opening_count_id' => $opening->id,
        'closing_count_id' => $closing->id,
    ])
        ->assertOk()
        ->assertSee('Unexplained, at selling price')
        // Three units gone with no sale behind them, at the frozen selling price.
        ->assertSee('₦300,000.00')
        ->assertSee('Approximate');
});

test('the goods block and the handover list render on screen', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    App\Models\Procurement::create([
        'vendor_id'  => $ctx['vendor']->id,
        'store_id'   => $ctx['store']->id,
        'supplier_id' => App\Models\Supplier::create([
            'vendor_id' => $ctx['vendor']->id, 'name' => 'Lagos Wholesale',
        ])->id,
        'total_cost' => 180000,
        'amount_paid' => 180000,
        'payment_status' => 'full',
        'payment_method' => 'bank_transfer',
        'status'     => 'completed',
        'created_by' => $ctx['owner']->id,
    ]);

    closeScreen($ctx, [
        'opening_count_id' => closeCount($ctx, 10)->id,
        'closing_count_id' => closeCount($ctx, 4)->id,
    ])
        ->assertOk()
        ->assertSee('What happened to the goods')
        ->assertSee('Opening stock')
        ->assertSee('Stock bought in')
        ->assertSee('Available to sell')
        ->assertSee('Left the shelf, at cost')
        ->assertSee('Lagos Wholesale')
        // Cost and selling price must never look like the same kind of number.
        ->assertSee('At cost, not selling price')
        // Each handover, named and dated, beside the goods.
        ->assertSee('Money handed over in this period')
        ->assertSee($ctx['cashier']->name);
});

test('closing freezes the figures and carries the baseline forward', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 7);

    closeScreen($ctx, [
        'opening_count_id' => $opening->id,
        'closing_count_id' => $closing->id,
    ])->callAction('close')->assertHasNoActionErrors();

    $close = StoreAccountClose::latestFor($ctx['store']->id);

    expect($close)->not->toBeNull()
        ->and($close->opening_count_id)->toBe($opening->id)
        ->and($close->closing_count_id)->toBe($closing->id)
        ->and($close->opening_source)->toBe(StoreAccountClose::OPENING_SELECTED)
        ->and((float) $close->value_sold)->toBe(100000.0)
        ->and((float) $close->shortage)->toBe(30000.0)
        // Frozen, not a live view: later trade must not move it.
        ->and($close->figure('balance.value_sold'))->toEqual(100000);
});

test('the second close carries the opening forward with nothing to choose', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    $first = closePeriod(
        $ctx,
        closeCount($ctx, 7),
        openingCount: closeCount($ctx, 10),
        from: now()->subMonths(2),
        to: now()->subMonth(),
    );

    $second = closeCount($ctx, 4);

    closeScreen($ctx, ['closing_count_id' => $second->id])
        ->callAction('close')
        ->assertHasNoActionErrors();

    $close = StoreAccountClose::latestFor($ctx['store']->id);

    expect($close->id)->not->toBe($first->id)
        ->and($close->opening_source)->toBe(StoreAccountClose::OPENING_CARRIED)
        // Nobody picked this. It is what the last period closed on.
        ->and($close->opening_count_id)->toBe($first->closing_count_id)
        ->and($close->previous_close_id)->toBe($first->id);
});

test('a stale opening selection cannot override the carried-forward baseline', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    $first = closePeriod(
        $ctx,
        closeCount($ctx, 7),
        openingCount: closeCount($ctx, 10),
        from: now()->subMonths(2),
        to: now()->subMonth(),
    );

    $decoy = closeCount($ctx, 99);

    // Left over in the filter state from before the branch had ever been
    // closed. The chain owns the opening, so this must be ignored outright.
    closeScreen($ctx, [
        'opening_count_id' => $decoy->id,
        'closing_count_id' => closeCount($ctx, 4)->id,
    ])->callAction('close')->assertHasNoActionErrors();

    expect(StoreAccountClose::latestFor($ctx['store']->id)->opening_count_id)
        ->toBe($first->closing_count_id);
});

test('closing is refused without a count of the shelf', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);

    closeScreen($ctx)->callAction('close');

    // The money side balances whether or not goods left unrecorded, so closing
    // on it alone would sign off the half that cannot see a theft.
    expect(StoreAccountClose::forStore($ctx['store']->id)->count())->toBe(0);
});

test('reading the screen does not carry the power to close it', function () {
    $ctx = closeContext(onShelf: 10);
    $reader = closeStaff($ctx, ['view_store_settlement']);
    closePanel($ctx, $reader);

    $closing = closeCount($ctx, 7);

    // Visible, because somebody has to be able to look at it.
    closeScreen($ctx, ['closing_count_id' => $closing->id])
        ->assertOk()
        ->assertSee('closing a period is a separate permission')
        ->assertActionHidden('close');

    expect(StoreAccountClose::forStore($ctx['store']->id)->count())->toBe(0);
});

test('the close permission alone is enough to close', function () {
    $ctx = closeContext(onShelf: 10);
    $closer = closeStaff($ctx, ['view_store_settlement', 'close_store_period']);
    closePanel($ctx, $closer);

    closeScreen($ctx, ['closing_count_id' => closeCount($ctx, 7)->id])
        ->assertActionVisible('close')
        ->callAction('close')
        ->assertHasNoActionErrors();

    expect(StoreAccountClose::latestFor($ctx['store']->id)->closed_by)->toBe($closer->id);
});

test('switching branch leaves no totals from the branch before it', function () {
    $ctx = closeContext();
    closePanel($ctx);

    $other = Store::create([
        'vendor_id' => $ctx['vendor']->id,
        'name'      => 'Second Branch',
        'is_default' => false,
    ]);

    closeSale($ctx, 'cash', 100000);

    $screen = closeScreen($ctx)->assertSee('₦100,000.00');

    // Nothing has ever been sold at the second branch, so nothing from the
    // first may survive the switch.
    $screen->set('filters.store_id', $other->id)
        ->assertOk()
        ->assertDontSee('₦100,000.00')
        ->assertSee('₦0.00');
});

test('switching the period recomputes rather than reusing the last window', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000, ['completed_at' => now()->subMonths(4)]);

    // A window that does not reach back far enough to hold that sale.
    $screen = closeScreen($ctx, ['from' => now()->subDays(3)->toDateString()])
        ->assertDontSee('₦100,000.00');

    $screen->set('filters.from', now()->subMonths(6)->toDateString())
        ->assertOk()
        ->assertSee('₦100,000.00');
});

test('a branch that has never been closed says so', function () {
    $ctx = closeContext();
    closePanel($ctx);

    closeScreen($ctx)
        ->assertOk()
        ->assertSee('This branch has never been closed');
});

test('the printable close carries the balance, the variance and a signoff', function () {
    $ctx = closeContext(onShelf: 10);
    closePanel($ctx);

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 7);

    $close = closePeriod($ctx, $closing, openingCount: $opening, figures: closeBalance($ctx, $closing, $opening));

    $this->actingAs($ctx['owner'])
        ->get(route('account-close.show', $close))
        ->assertOk()
        ->assertSee('Store Account Close')
        ->assertSee($close->reference)
        ->assertSee('Value sold')
        ->assertSee('Where that value went')
        ->assertSee('Stock counted')
        ->assertSee('The chain')
        ->assertSee('Signoff')
        // The close statement must say the period was not locked, because it
        // was not, and a document implying otherwise would be a lie people act on.
        ->assertSee('a correction dated inside this period can still land');
});

test('the close downloads as a pdf', function () {
    $ctx = closeContext();
    closePanel($ctx);

    $close = closePeriod($ctx, closeCount($ctx, 7));

    $response = $this->actingAs($ctx['owner'])->get(route('account-close.pdf', $close));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain($close->reference . '.pdf');
});

test('a close cannot be read by somebody from another business', function () {
    // Deliberately no panel here: this is a plain link, and the whole point is
    // that holding one gets an outsider nowhere without a panel session.
    $ctx = closeContext();

    $close = closePeriod($ctx, closeCount($ctx, 7));

    $outsider = User::factory()->create();
    App\Models\Vendor::create(['user_id' => $outsider->id, 'name' => 'Someone Else Ltd']);

    // It names people and says what a branch is short, so the only thing that
    // matters is that none of it comes back.
    $response = $this->actingAs($outsider)->get(route('account-close.show', $close));

    expect($response->status())->not->toBe(200);
});
