<?php

use App\Models\PosReturn;
use App\Models\User;
use App\Services\Cash\StoreReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/CloseHelpers.php';

uses(RefreshDatabase::class);

/**
 * The balance asks one question: the value a branch sold, against where that
 * value went.
 *
 * Card, transfer and credit appear on both sides and cancel, so the only thing
 * that can actually go missing is cash. That is not a convenient coincidence —
 * it is the property that makes the gap meaningful, and most of what these
 * tests do is prove it still holds as each tender is added.
 */

test('the balance closes to zero when everything was handed over', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 100000);

    $b = closeBalance($ctx)['balance'];

    expect($b['value_sold'])->toBe(100000.0)
        ->and($b['right_total'])->toBe(100000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('the gap is the same shortage Checkmate already reports', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);
    closeSale($ctx, 'card', 40000);
    closeSale($ctx, 'bank_transfer', 25000);
    closeSale($ctx, 'debt', 30000);
    closeRemit($ctx, 70000);

    $view = closeBalance($ctx);

    $checkmate = app(StoreReconciliation::class)
        ->forStore($ctx['store'], now()->subMonth(), now()->addDay());

    // Derived, never recomputed. If these ever disagree the balance is telling
    // somebody they are short of money by arithmetic nobody else in the system
    // performs.
    expect($view['balance']['shortage'])->toBe($checkmate['cash']['shortage'])
        ->and($view['checkmate']['agrees'])->toBeTrue()
        ->and($view['checkmate']['difference'])->toBe(0.0);
});

test('VAT sits on the left, because the till took it and somebody must hand it over', function () {
    $ctx = closeContext();

    // Exactly what the till writes at the default 7.5%.
    closeSale($ctx, 'cash', 107500, ['subtotal' => 100000, 'vat_amount' => 7500]);
    closeRemit($ctx, 107500);

    $b = closeBalance($ctx)['balance'];

    // The reporting stack would call this 100,000 — revenue there excludes VAT
    // on purpose. Using that figure here would read as a 7,500 shortage of
    // money the cashier genuinely handed over.
    expect($b['value_sold'])->toBe(107500.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('card and transfer cancel across the two sides', function () {
    $ctx = closeContext();

    closeSale($ctx, 'card', 40000);
    closeSale($ctx, 'bank_transfer', 25000);

    $b = closeBalance($ctx)['balance'];

    // Nothing was ever in a drawer, so nothing is owed and nothing is missing.
    expect($b['value_sold'])->toBe(65000.0)
        ->and($b['card'])->toBe(40000.0)
        ->and($b['bank_transfer'])->toBe(25000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('a credit sale lands on the right and is not a shortage', function () {
    $ctx = closeContext();

    closeSale($ctx, 'debt', 30000);

    $b = closeBalance($ctx)['balance'];

    // The goods left, so the value sold is real. Nobody is short of it — it is
    // owed, which is a different conversation with a different person.
    expect($b['value_sold'])->toBe(30000.0)
        ->and($b['period_debt'])->toBe(30000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('the cash leg of a split is owed back and the card leg is not', function () {
    $ctx = closeContext();

    closeSplitSale($ctx, cash: 60000, card: 40000);
    closeRemit($ctx, 60000);

    $b = closeBalance($ctx)['balance'];

    expect($b['value_sold'])->toBe(100000.0)
        ->and($b['card'])->toBe(40000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('cash that was never handed over is the shortage', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    $view = closeBalance($ctx);

    expect($view['balance']['shortage'])->toBe(30000.0)
        ->and($view['balance']['overage'])->toBe(0.0)
        ->and($view['checkmate']['agrees'])->toBeTrue();
});

test('handing over more than the tills took is an overage, not a shortage', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 115000);

    $b = closeBalance($ctx)['balance'];

    expect($b['shortage'])->toBe(-15000.0)
        ->and($b['overage'])->toBe(15000.0);
});

test('declared till spending is where the value went, not a shortage', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);

    app(App\Actions\Cash\LogTillExpenseAction::class)->execute(
        loggedBy: $ctx['cashier'],
        store:    $ctx['store'],
        amount:   15000,
        reason:   'Generator fuel',
    );

    closeRemit($ctx, 85000);

    $b = closeBalance($ctx)['balance'];

    expect($b['till_expenses'])->toBe(15000.0)
        ->and($b['right_total'])->toBe(100000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('a refund comes off both sides, by the route the money went back out', function () {
    $ctx = closeContext();

    $sale = closeSale($ctx, 'card', 40000);

    PosReturn::create([
        'reference'        => 'RET-' . uniqid(),
        'vendor_id'        => $ctx['vendor']->id,
        'original_sale_id' => $sale->id,
        'cashier_id'       => $ctx['cashier']->id,
        'return_items'     => [],
        'refund_amount'    => 15000,
        'refund_method'    => 'card',
    ]);

    $b = closeBalance($ctx)['balance'];

    // Off the left as value that did not stay sold, and off the card leg on the
    // right. Netting only one side would read as a 15,000 shortage.
    expect($b['gross_sales'])->toBe(40000.0)
        ->and($b['refunds'])->toBe(15000.0)
        ->and($b['value_sold'])->toBe(25000.0)
        ->and($b['card'])->toBe(25000.0)
        ->and($b['shortage'])->toBe(0.0);
});

test('pending and disputed handovers are shown rather than assumed good', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);

    app(App\Actions\Cash\SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 100000,
    );

    $view = closeBalance($ctx);

    // Money waiting on a receiver is nobody's problem yet, but it is not on the
    // right either — closing without seeing it is how a period gets signed blind.
    expect($view['submissions']['pending'])->toBe(100000.0)
        ->and($view['submissions']['confirmed'])->toBe(0.0)
        ->and($view['balance']['shortage'])->toBe(100000.0);
});

test('the count catches stock that left without a sale being rung', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = closeCount($ctx, 10);
    // No sale, no delivery, no transfer. Three units simply are not there.
    $closing = closeCount($ctx, 7);

    $v = closeBalance($ctx, $closing, $opening)['count_variance'];

    expect($v['available'])->toBeTrue()
        ->and($v['units'])->toBe(3)
        // The money side balances perfectly here: expected cash was calculated
        // from records that were never made. Only counting the goods sees it.
        ->and($v['at_selling'])->toBe(300000.0)
        ->and($v['unexplained_units'])->toBe(3)
        ->and($v['lines_short'])->toBe(1);
});

test('stock that left as a recorded sale is not a variance', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = closeCount($ctx, 10);
    closeMovement($ctx, 'pos_sale', -2);
    $closing = closeCount($ctx, 8);

    $v = closeBalance($ctx, $closing, $opening)['count_variance'];

    expect($v['units'])->toBe(0)
        ->and($v['at_selling'])->toBe(0.0);
});

test('a correction from either kind of stock count is not mistaken for goods moving', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = closeCount($ctx, 10);

    // This codebase counts stock two ways: the settlement count posts
    // 'count_adjustment', the two-person blind count on the Inventory Count
    // page posts 'audit_correction'. Both are a correction a count produced.
    closeMovement($ctx, 'count_adjustment', -2);
    closeMovement($ctx, 'audit_correction', -3);

    $closing = closeCount($ctx, 10);

    $v = closeBalance($ctx, $closing, $opening)['count_variance'];

    // Counting either as stock movement would let last period's written-off
    // shortfall reappear as this period's goods.
    expect($v['units'])->toBe(0)
        ->and($v['at_selling'])->toBe(0.0)
        ->and($v['top_offenders'])->toBeEmpty();
});

test('a transfer to another branch is reported, not accused', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = closeCount($ctx, 10);
    closeMovement($ctx, 'store_transfer', -3);
    $closing = closeCount($ctx, 7);

    $v = closeBalance($ctx, $closing, $opening)['count_variance'];

    // The headline follows the rule as specified — opening plus received less
    // what was rung — so the transfer shows up in it.
    expect($v['units'])->toBe(3)
        // Beside it, the same gap with movements that ARE accounted for taken
        // back out. Three units moved to another branch is not three stolen.
        ->and($v['unexplained_units'])->toBe(0)
        ->and($v['unexplained_at_selling'])->toBe(0.0)
        ->and($v['top_offenders'][0]['other_moves'])->toBe(-3);
});

test('a delivery in the period raises what the shelf should hold', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = closeCount($ctx, 10);
    closeMovement($ctx, 'restock', 5);
    $closing = closeCount($ctx, 15);

    $view = closeBalance($ctx, $closing, $opening);

    expect($view['count_variance']['units'])->toBe(0)
        ->and($view['count_variance']['top_offenders'])->toBeEmpty()
        // Units, never money. Procurement is paid from the business account, so
        // putting its value on the right would net it against cash it never
        // touched and invent a shortage.
        ->and($view['procurement']['units_received'])->toBe(5)
        ->and($view['procurement']['is_money_line'])->toBeFalse();
});

test('the count valuation says out loud that it is approximate', function () {
    $ctx = closeContext(onShelf: 10);

    $v = closeBalance($ctx, closeCount($ctx, 7), closeCount($ctx, 10))['count_variance'];

    expect($v['approximate'])->toBeTrue()
        ->and($v['basis'])->toContain('Approximate')
        ->and($v['basis'])->toContain('Recorded sales is the exact figure');
});

test('without a closing count there is no variance to report', function () {
    $ctx = closeContext();

    $v = closeBalance($ctx)['count_variance'];

    // Absent is a meaningful answer. A period closed on the money alone has
    // only checked the half that balances when goods walk out unrecorded.
    expect($v['available'])->toBeFalse()
        ->and($v['at_selling'])->toBe(0.0)
        ->and($v['approximate'])->toBeTrue();
});

test('the debt tender and the customer ledger agree when the charge was posted', function () {
    $ctx = closeContext();

    $customer = App\Models\PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id,
        'name'      => 'Credit Customer',
    ]);

    $sale = closeSale($ctx, 'debt', 30000, ['customer_id' => $customer->id]);
    app(App\Actions\Pos\ChargeCustomerDebtAction::class)->execute($sale);

    $check = closeBalance($ctx)['debt_check'];

    // Both read the sale's tender rows rather than its total, so in the shape
    // production actually produces they are the same money counted twice and
    // must land on the same number.
    expect($check['tender'])->toBe(30000.0)
        ->and($check['ledger_charges'])->toBe(30000.0)
        ->and($check['agrees'])->toBeTrue();
});

test('a credit sale whose charge never reached the ledger shows as a difference', function () {
    $ctx = closeContext();

    // The tender row exists; no charge was ever posted against it. Surfaced as
    // a difference rather than swallowed, because a receivable nobody can be
    // asked to pay is worth more attention than a rounding error.
    closeSale($ctx, 'debt', 30000);

    $check = closeBalance($ctx)['debt_check'];

    expect($check['tender'])->toBe(30000.0)
        ->and($check['ledger_charges'])->toBe(0.0)
        ->and($check['agrees'])->toBeFalse()
        ->and($check['difference'])->toBe(30000.0);
});

test('the goods block runs opening plus purchases less closing, at cost', function () {
    $ctx = closeContext(onShelf: 10);

    $supplier = App\Models\Supplier::create([
        'vendor_id' => $ctx['vendor']->id,
        'name'      => 'Lagos Wholesale',
    ]);

    App\Models\Procurement::create([
        'vendor_id'  => $ctx['vendor']->id,
        'store_id'   => $ctx['store']->id,
        'supplier_id' => $supplier->id,
        'total_cost' => 180000,
        'amount_paid' => 180000,
        'payment_status' => 'full',
        'payment_method' => 'bank_transfer',
        'status'     => 'completed',
        'created_by' => $ctx['owner']->id,
    ]);

    // Cost price on the fixture product is 60,000.
    $opening = closeCount($ctx, 10);   // 600,000 at cost
    $closing = closeCount($ctx, 4);    // 240,000 at cost

    $m = closeBalance($ctx, $closing, $opening)['stock_movement'];

    expect($m['available'])->toBeTrue()
        ->and($m['opening_value'])->toBe(600000.0)
        ->and($m['purchases_value'])->toBe(180000.0)
        ->and($m['available_value'])->toBe(780000.0)
        ->and($m['closing_value'])->toBe(240000.0)
        // What it cost to buy the things that are no longer there.
        ->and($m['left_at_cost'])->toBe(540000.0)
        // The model stamps its own reference on create, so this asserts the
        // shape rather than the value the fixture asked for.
        ->and($m['purchases'][0]['reference'])->toStartWith('GP-PROC-')
        ->and($m['purchases'][0]['supplier'])->toBe('Lagos Wholesale')
        ->and($m['purchases'][0]['date'])->not->toBeNull()
        // Never to be read against the value sold above.
        ->and($m['basis'])->toContain('the difference is not profit');
});

test('the goods block carries a selling-price column beside the cost one', function () {
    $ctx = closeContext(onShelf: 10);

    $procurement = App\Models\Procurement::create([
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

    // Three units at 60,000 cost and 100,000 retail — the delivery's own lines
    // carry the selling price, so retail is recorded rather than guessed.
    App\Models\ProcurementItem::create([
        'procurement_id' => $procurement->id,
        'product_id'     => $ctx['product']->id,
        'quantity'       => 3,
        'unit_cost'      => 60000,
        'selling_price'  => 100000,
    ]);

    // Fixture product: 60,000 cost, 100,000 selling.
    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 4);

    $m = closeBalance($ctx, $closing, $opening)['stock_movement'];

    expect($m['opening_value'])->toBe(600000.0)
        ->and($m['opening_selling'])->toBe(1000000.0)
        ->and($m['purchases_value'])->toBe(180000.0)
        ->and($m['purchases_selling'])->toBe(300000.0)
        ->and($m['available_selling'])->toBe(1300000.0)
        ->and($m['closing_selling'])->toBe(400000.0)
        // What the goods that are gone would have sold for.
        ->and($m['left_at_selling'])->toBe(900000.0)
        ->and($m['purchases'][0]['selling'])->toBe(300000.0);
});

test('a partial count is visible as partial, against what the branch holds', function () {
    $ctx = closeContext(onShelf: 10);

    // A second product on the shelf that the count never touched.
    $uncounted = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id,
        'store_id'  => $ctx['store']->id,
        'category_id' => $ctx['product']->category_id,
        'name' => 'Uncounted Phone', 'price' => 250000, 'cost_price' => 150000,
        'stock_quantity' => 6, 'reserved_stock' => 0, 'status' => 'published',
    ]);
    App\Models\ProductStoreStock::updateOrCreate(
        ['product_id' => $uncounted->id, 'store_id' => $ctx['store']->id],
        ['quantity' => 6, 'reserved' => 0],
    );

    $m = closeBalance($ctx, closeCount($ctx, 4), closeCount($ctx, 10))['stock_movement'];

    // The closing value only ever covers what was counted — which is exactly
    // why a small figure has to be readable as a partial count rather than as a
    // branch that emptied.
    expect($m['closing_products'])->toBe(1)
        ->and($m['closing_selling'])->toBe(400000.0)
        ->and($m['on_record']['products'])->toBe(2)
        ->and($m['on_record']['units'])->toBe(16)
        // 10 x 100,000 + 6 x 250,000
        ->and($m['on_record']['selling'])->toBe(2500000.0)
        ->and($m['on_record']['cost'])->toBe(1500000.0);
});

test('the goods block says so when it has only half the counts it needs', function () {
    $ctx = closeContext(onShelf: 10);

    // Without a closing figure, "what left" would just be everything.
    $m = closeBalance($ctx, null, closeCount($ctx, 10))['stock_movement'];

    expect($m['available'])->toBeFalse();
});

test('stock bought with cash is flagged, because it may have come out of the till', function () {
    $ctx = closeContext();

    App\Models\Procurement::create([
        'vendor_id'  => $ctx['vendor']->id,
        'store_id'   => $ctx['store']->id,
        'supplier_id' => App\Models\Supplier::create([
            'vendor_id' => $ctx['vendor']->id,
            'name'      => 'Cash Supplier',
        ])->id,
        'total_cost' => 50000,
        'amount_paid' => 50000,
        'payment_status' => 'full',
        'payment_method' => 'cash',
        'status'     => 'completed',
        'created_by' => $ctx['owner']->id,
    ]);

    $m = closeBalance($ctx)['stock_movement'];

    // Nothing in the data says whether this came from the till or the business
    // account, and the difference decides whether it also belongs on the money
    // side. Flagged for a person to answer rather than assumed either way.
    expect($m['purchases'][0]['paid_cash'])->toBeTrue()
        ->and($m['purchases_value'])->toBe(50000.0);
});

test('every handover in the period is listed, settled or not', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 60000);

    app(App\Actions\Cash\SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'],
        amount: 25000, reason: 'Second drop',
    );

    $subs = closeBalance($ctx)['submissions'];

    expect($subs['count'])->toBe(2)
        ->and($subs['rows'][0]['amount'])->toBe(60000.0)
        ->and($subs['rows'][0]['counts'])->toBeTrue()
        // Real money that moved, still not on the right of the balance.
        ->and($subs['rows'][1]['amount'])->toBe(25000.0)
        ->and($subs['rows'][1]['counts'])->toBeFalse()
        ->and($subs['rows'][1]['from'])->toBe($ctx['cashier']->name);
});

test('profit never appears on the balance', function () {
    $ctx = closeContext();

    closeSale($ctx, 'cash', 100000);

    $view = closeBalance($ctx);

    // A locked rule, asserted rather than trusted: money reconciliation asks
    // where the cash went, profit asks what the goods cost, and a page that
    // carried both is a page where a shortage gets argued about in margin.
    $flat = json_encode($view);

    expect($flat)->not->toContain('"profit"')
        ->and($flat)->not->toContain('"cogs"')
        ->and($flat)->not->toContain('"margin"');
});

test('closing a period needs its own permission, which confirming cash does not carry', function () {
    $ctx = closeContext();
    cashRoles($ctx['vendor']);

    $collector = User::factory()->create();
    $collector->stores()->attach($ctx['store']->id);
    $ctx['vendor']->users()->syncWithoutDetaching([$collector->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    // Trusted with the money itself, and still not with declaring it settled.
    $collector->givePermissionTo('receive_cash');

    app(App\Actions\Cash\CloseStoreAccountAction::class)->execute(
        closedBy:     $collector,
        store:        $ctx['store'],
        from:         now()->subMonth(),
        to:           now(),
        closingCount: closeCount($ctx, 7),
        figures:      closeFigures(),
    );
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

test('the close permission alone is enough, and only at the branch it was given for', function () {
    $ctx = closeContext();
    cashRoles($ctx['vendor']);

    $manager = User::factory()->create();
    $manager->stores()->attach($ctx['store']->id);
    $ctx['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $manager->givePermissionTo('close_store_period');

    $close = app(App\Actions\Cash\CloseStoreAccountAction::class)->execute(
        closedBy:     $manager,
        store:        $ctx['store'],
        from:         now()->subMonth(),
        to:           now(),
        closingCount: closeCount($ctx, 7),
        figures:      closeFigures(),
    );

    expect($close->closed_by)->toBe($manager->id);
});

test('the frozen payload is the computed one, not a second calculation', function () {
    $ctx = closeContext(onShelf: 10);

    closeSale($ctx, 'cash', 100000);
    closeRemit($ctx, 70000);

    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 7);

    $figures = closeBalance($ctx, $closing, $opening);

    $close = app(App\Actions\Cash\CloseStoreAccountAction::class)->execute(
        closedBy:     $ctx['owner'],
        store:        $ctx['store'],
        from:         now()->subMonth(),
        to:           now()->addDay(),
        closingCount: $closing,
        figures:      $figures,
        openingCount: $opening,
    );

    // The columns the list screen sorts on are copies of the payload the screen
    // showed, so a close can never disagree with the page it was made from.
    expect((float) $close->value_sold)->toBe(100000.0)
        ->and((float) $close->shortage)->toBe(30000.0)
        ->and((float) $close->variance_at_selling)->toBe(300000.0)
        ->and($close->figure('checkmate.agrees'))->toBeTrue();
});
