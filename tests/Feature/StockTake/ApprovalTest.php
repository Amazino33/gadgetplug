<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Models\AisleProduct;
use App\Models\AuditSession;
use App\Models\InventoryLedger;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\StockTake;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Services\StockTake\StockTakeApprover;
use App\Services\StockTake\StockTakeCounter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

/**
 * Step 6: results, approval, recounts, the stock correction and audit lines.
 */

afterEach(fn () => Carbon::setTestNow());

test('system 50, counted 48, 10 sold before approval: stock ends at 38', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);

    $result = stResultFor($task, $milk);
    expect($result->system_qty_at_count)->toBe(50)
        ->and($result->variance_qty)->toBe(-2)
        ->and($result->variance_value)->toEqual(-2000.0);

    Carbon::setTestNow('2026-11-24 13:00:00');
    stSell($ctx, $milk, 10);
    expect(stShelf($ctx, $milk))->toBe(40);

    Carbon::setTestNow('2026-11-24 16:00:00');
    stApprover()->approve($ctx['admin'], $result);

    $result->refresh();
    $ledger = InventoryLedger::where('transaction_type', 'count_adjustment')->sole();

    expect(stShelf($ctx, $milk))->toBe(38)
        ->and($result->status)->toBe('approved')
        ->and($result->applied_system_qty)->toBe(50)
        ->and($result->applied_adjustment_qty)->toBe(-2)
        ->and($ledger->quantity_change)->toBe(-2)
        ->and($ledger->audit_session_id)->toBe($result->audit_session_id)
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_APPROVED);
});

test('the approval writes an audit line with counted, system, variance and loss value', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);

    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    $line = AuditSession::sole();

    expect($line->stock_take_task_id)->toBe($task->id)
        ->and($line->product_id)->toBe($milk->id)
        ->and($line->count_a)->toBe(48)
        ->and($line->system_quantity)->toBe(50)
        ->and($line->countedVariance())->toBe(-2)
        ->and((float) $line->loss_value)->toEqual(2000.0)
        ->and($line->storekeeper_a_id)->toBe($ctx['keeperA']->id)
        ->and($line->manager_id)->toBe($ctx['admin']->id)
        ->and($line->status)->toBe('resolved_by_override');
});

test('a till sale rung before the count but synced after it is in the system figure at approval', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $milk->update(['price' => 1500]);

    // Counted at 10:00: the shelf held 49 — one had gone at 09:55 on a till
    // with no signal.
    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 49]]);

    // Built before the sale arrived: it looks one short.
    expect(stResultFor($task, $milk)->variance_qty)->toBe(-1);

    Carbon::setTestNow('2026-11-24 11:00:00');
    Sanctum::actingAs($ctx['owner'], ['pos']);
    test()->postJson('/api/pos/sync', ['vendor_id' => $ctx['vendor']->id, 'sales' => [[
        'offline_id' => 'OFF-'.uniqid(), 'store_id' => $ctx['store']->id,
        'items' => [['product_id' => $milk->id, 'product_name' => $milk->name, 'unit_price' => 1500, 'quantity' => 1, 'total' => 1500]],
        'discount_amount' => 0, 'vat_rate' => 0, 'subtotal' => 1500, 'payment_method' => 'cash',
        'total' => 1500, 'vat_amount' => 0, 'amount_tendered' => 1500, 'change_given' => 0,
        'completed_at' => '2026-11-24T09:55:00+00:00',
    ]]])->assertJsonPath('results.0.status', 'synced');

    Carbon::setTestNow('2026-11-24 12:00:00');
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    expect(stResultFor($task, $milk)->applied_system_qty)->toBe(49)
        ->and(stResultFor($task, $milk)->applied_adjustment_qty)->toBe(0)
        ->and(InventoryLedger::where('transaction_type', 'count_adjustment')->exists())->toBeFalse()
        ->and(stShelf($ctx, $milk))->toBe(49);
});

/** The 09:55 sale reaching the server late, through the real till sync. */
function stLateTillSale(array $ctx, $product, string $rungAt): void
{
    Sanctum::actingAs($ctx['owner'], ['pos']);
    test()->postJson('/api/pos/sync', ['vendor_id' => $ctx['vendor']->id, 'sales' => [[
        'offline_id' => 'OFF-'.uniqid(), 'store_id' => $ctx['store']->id,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 1500, 'quantity' => 1, 'total' => 1500]],
        'discount_amount' => 0, 'vat_rate' => 0, 'subtotal' => 1500, 'payment_method' => 'cash',
        'total' => 1500, 'vat_amount' => 0, 'amount_tendered' => 1500, 'change_given' => 0,
        'completed_at' => $rungAt,
    ]]])->assertJsonPath('results.0.status', 'synced');
}

test('approved, then a sale from before the count syncs: stock is corrected with no button press', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $milk->update(['price' => 1500]);

    // 10:00 the shelf holds 49: one went at 09:55 on a till with no signal.
    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 49]]);

    // 12:00 approved against books that do not have that sale yet.
    Carbon::setTestNow('2026-11-24 12:00:00');
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));
    expect(stShelf($ctx, $milk))->toBe(49);

    // 13:00 the sale arrives. Its deduction would leave 48, one short of the
    // shelf; the correction lands with it.
    Carbon::setTestNow('2026-11-24 13:00:00');
    stLateTillSale($ctx, $milk, '2026-11-24T09:55:00+00:00');

    $result = stResultFor($task, $milk);
    $auto   = AuditSession::where('note', StockTakeApprover::AUTO_RECHECK_NOTE)->sole();

    expect(stShelf($ctx, $milk))->toBe(49)
        ->and($result->recheck_needed_at)->toBeNull()
        ->and($result->applied_system_qty)->toBe(49)
        ->and($result->applied_adjustment_qty)->toBe(0)
        ->and(StockTakeApprover::needsRecheck($result))->toBeFalse()
        // Its own audit line, linked to the original result.
        ->and($auto->stock_take_result_id)->toBe($result->id)
        ->and($auto->stock_take_task_id)->toBe($task->id)
        ->and($auto->system_quantity)->toBe(49)
        ->and($auto->manager_id)->toBeNull()
        ->and($result->auditLines()->count())->toBe(2)
        ->and(InventoryLedger::where('audit_session_id', $auto->id)->sole()->quantity_change)->toBe(1);
});

test('the automatic re-check runs on a stock take that is already closed', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 49]]);
    Carbon::setTestNow('2026-11-24 12:00:00');
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));
    stPlanner()->close($ctx['admin'], $st->fresh());
    expect($st->fresh()->isOpen())->toBeFalse();

    Carbon::setTestNow('2026-11-25 09:00:00');
    app(AdjustStockAction::class)->execute(productId: $milk->id, quantityChanged: -1, transactionType: 'pos_sale',
        store: $ctx['store']->id, occurredAt: Carbon::parse('2026-11-24 09:55:00'));

    expect(stShelf($ctx, $milk))->toBe(49)
        ->and(AuditSession::where('note', StockTakeApprover::AUTO_RECHECK_NOTE)->count())->toBe(1);
});

test('a late sale rung after the count needs no correction', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 49]]);
    Carbon::setTestNow('2026-11-24 12:00:00');
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    // Rung at 10:30, after the count, synced at 13:00: a normal later sale.
    Carbon::setTestNow('2026-11-24 13:00:00');
    app(AdjustStockAction::class)->execute(productId: $milk->id, quantityChanged: -1, transactionType: 'pos_sale',
        store: $ctx['store']->id, occurredAt: Carbon::parse('2026-11-24 10:30:00'));

    expect(stShelf($ctx, $milk))->toBe(48)
        ->and(stResultFor($task, $milk)->recheck_needed_at)->toBeNull()
        ->and(AuditSession::count())->toBe(1);
});

test('if the automatic correction fails, the sale stands and the result waits for Re-check', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 49]]);
    Carbon::setTestNow('2026-11-24 12:00:00');
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    // The automatic run breaks.
    $real = app(StockTakeApprover::class);
    app()->instance(StockTakeApprover::class, new class($real) {
        public function __construct(private StockTakeApprover $real) {}
        public function correctLateSales(...$args) { throw new RuntimeException('database went away'); }
        public function __call($name, $args) { return $this->real->{$name}(...$args); }
    });

    Carbon::setTestNow('2026-11-24 13:00:00');
    app(AdjustStockAction::class)->execute(productId: $milk->id, quantityChanged: -1, transactionType: 'pos_sale',
        store: $ctx['store']->id, occurredAt: Carbon::parse('2026-11-24 09:55:00'));

    $result = stResultFor($task, $milk);

    // The sale itself went through; the result is marked for the button.
    expect(stShelf($ctx, $milk))->toBe(48)
        ->and($result->recheck_needed_at)->not->toBeNull();

    app()->instance(StockTakeApprover::class, $real);

    expect($real->recheck($ctx['admin'], $result))->toBe(1);

    expect(stShelf($ctx, $milk))->toBe(49)
        ->and($result->fresh()->recheck_needed_at)->toBeNull()
        ->and(AuditSession::where('note', 'Re-check (late till sale)')->sole()->manager_id)->toBe($ctx['admin']->id);
});

test('a recount replaces the original and never adds to it', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);
    $original = stResultFor($task, $milk);
    expect($original->needs_recount)->toBeTrue();

    [$recount] = stApprover()->sendToRecount($ctx['admin'], collect([$original]), $ctx['keeperB'], '2026-11-25');
    expect($original->fresh()->status)->toBe('sent_to_recount')
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_PARTIALLY_APPROVED)
        ->and($recount->parent_task_id)->toBe($task->id)
        ->and($recount->products->pluck('id')->all())->toBe([$milk->id]);

    stCountAndHandIn($ctx, $recount, $ctx['keeperB'], [[$aisle, $milk, 49]]);
    $second = stResultFor($recount, $milk);
    expect($second->recount_of_id)->toBe($original->id);

    stApprover()->approve($ctx['admin'], $second);

    expect(stShelf($ctx, $milk))->toBe(49)
        ->and(InventoryLedger::where('transaction_type', 'count_adjustment')->sum('quantity_change'))->toEqual(-1)
        ->and($original->fresh()->status)->toBe('rejected')
        ->and(StockTakeResult::where('status', 'approved')->count())->toBe(1);

    // The original cannot then be applied as well.
    expect(fn () => stApprover()->approve($ctx['admin'], $original->fresh()))->toThrow(ValidationException::class);
    expect(stShelf($ctx, $milk))->toBe(49);
});

test('the approver may choose the original instead; the recount is then rejected', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);
    $original = stResultFor($task, $milk);
    [$recount] = stApprover()->sendToRecount($ctx['admin'], collect([$original]), $ctx['keeperB'], null);
    stCountAndHandIn($ctx, $recount, $ctx['keeperB'], [[$aisle, $milk, 49]]);

    stApprover()->approve($ctx['admin'], $original->fresh());

    expect(stShelf($ctx, $milk))->toBe(30)
        ->and(stResultFor($recount, $milk)->status)->toBe('rejected')
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_APPROVED)
        ->and($recount->fresh()->status)->toBe(StockTakeTask::STATUS_APPROVED);
});

test('a recount cannot go to the person who counted it', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);

    expect(fn () => stApprover()->sendToRecount($ctx['admin'], collect([stResultFor($task, $milk)]), $ctx['keeperA'], null))
        ->toThrow(ValidationException::class, 'must be done by someone else');
});

test('cancelling a recount puts the original back to be decided', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);
    $original = stResultFor($task, $milk);
    [$recount] = stApprover()->sendToRecount($ctx['admin'], collect([$original]), $ctx['keeperB'], null);

    stPlanner()->cancelTask($ctx['admin'], $recount);

    expect($original->fresh()->status)->toBe('pending')
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_SUBMITTED);
});

test('nobody approves what they counted; the owner may, with a note shown on the audit line', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Peak Milk', 50);
    $aisle = stAisle($ctx, 'Aisle 2');
    stMap($ctx, $aisle, $milk);
    $st = stOpen($ctx);

    // The owner counts it themselves.
    setPermissionsTeamId($ctx['vendor']->id);
    $task = StockTakeTask::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'stock_take_id' => $st->id,
        'type' => StockTakeTask::TYPE_COUNT, 'assigned_to' => $ctx['owner']->id, 'created_by' => $ctx['owner']->id,
    ]);
    $task->attachAisles([$aisle->id]);
    stCountAndHandIn($ctx, $task, $ctx['owner'], [[$aisle, $milk, 47]]);
    $result = stResultFor($task, $milk);

    expect(fn () => stApprover()->approve($ctx['owner'], $result))->toThrow(ValidationException::class, 'Say why');

    stApprover()->approve($ctx['owner'], $result, 'Small shop, nobody else in today.');

    expect(AuditSession::sole()->note)->toBe('Small shop, nobody else in today.')
        ->and($result->fresh()->override_note)->toBe('Small shop, nobody else in today.');
});

test('a manager who counted either count of a recount pair cannot decide it', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    // The store admin may count too, for this test.
    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['admin']->givePermissionTo('perform_stock_takes');
    $manager2 = User::factory()->create();
    $ctx['vendor']->users()->attach($manager2->id);
    $manager2->stores()->attach($ctx['store']->id);
    $manager2->assignRole('inventory_manager');

    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);
    [$recount] = stApprover()->sendToRecount($manager2, collect([stResultFor($task, $milk)]), $ctx['admin'], null);
    stCountAndHandIn($ctx, $recount, $ctx['admin'], [[$aisle, $milk, 49]]);

    expect(fn () => stApprover()->approve($ctx['admin'], stResultFor($recount, $milk)))->toThrow(AuthorizationException::class);
    expect(fn () => stApprover()->approve($ctx['admin'], stResultFor($task, $milk)->fresh()))->toThrow(AuthorizationException::class);

    stApprover()->approve($manager2, stResultFor($recount, $milk));
    expect(stShelf($ctx, $milk))->toBe(49);
});

test('rejecting leaves stock alone', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);

    stApprover()->reject($ctx['admin'], stResultFor($task, $milk));

    expect(stShelf($ctx, $milk))->toBe(50)
        ->and(stResultFor($task, $milk)->status)->toBe('rejected')
        ->and(AuditSession::count())->toBe(0)
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_APPROVED);
});

test('approving a product found off its aisle list puts it on that aisle', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $sugar = stProduct($ctx, 'Sugar', 10);

    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 50], [$aisle, $sugar, 10]]);
    stApprover()->approve($ctx['admin'], stResultFor($task, $sugar));

    $mapping = AisleProduct::where('aisle_id', $aisle->id)->where('product_id', $sugar->id)->sole();

    expect($mapping->source)->toBe('count');
});

test('thresholds flag a recount by % or by ₦; approve-all leaves the flagged ones', function () {
    $ctx  = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $small = stProduct($ctx, 'Small', 100); $small->update(['cost_price' => 10]);     // −5 % , ₦50
    $pct   = stProduct($ctx, 'Pct', 10);    $pct->update(['cost_price' => 10]);       // −20 %, ₦20
    $money = stProduct($ctx, 'Money', 1000); $money->update(['cost_price' => 10000]); // −1 %, ₦100,000
    stMap($ctx, $aisle, $small, $pct, $money);
    $st   = stOpen($ctx, ['variance_threshold_percent' => 10, 'variance_threshold_value' => 5000]);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);

    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $small, 95], [$aisle, $pct, 8], [$aisle, $money, 990]]);

    expect(stResultFor($task, $small)->needs_recount)->toBeFalse()
        ->and(stResultFor($task, $pct)->needs_recount)->toBeTrue()
        ->and(stResultFor($task, $money)->needs_recount)->toBeTrue();

    expect(stApprover()->approveAllUnflagged($ctx['admin'], $st->id))->toBe(['approved' => 1, 'left' => 0]);

    expect(stResultFor($task, $small)->status)->toBe('approved')
        ->and(stResultFor($task, $pct)->status)->toBe('pending');
});

test('a product counted as not on the shelf is approved as a count of 0', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct(['books' => 6]);

    app(StockTakeCounter::class)->submit($ctx['keeperA'], $task, [$milk->id => 'not_on_shelf']);
    stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    expect(stShelf($ctx, $milk))->toBe(0);
});

test('acceptance 4: 20 on Aisle 2 and 30 in the store room are one result of 50', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Peak Milk', 50);
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    stMap($ctx, $a2, $milk);
    stMap($ctx, $room, $milk);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$a2->id, $room->id], $ctx['keeperA'], null);

    Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$a2, $milk, 20], [$room, $milk, 30]]);

    $result = stResultFor($task, $milk);

    expect($result->total_counted_base)->toBe(50)
        ->and($result->variance_qty)->toBe(0)
        ->and($result->needs_recount)->toBeFalse();
});

test('acceptance 8: another vendor cannot approve, reject, recount or re-check these results', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $other = stockTakeContext();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);
    $result = stResultFor($task, $milk);

    foreach ([$other['owner'], $other['admin']] as $stranger) {
        expect(fn () => stApprover()->approve($stranger, $result))->toThrow(AuthorizationException::class);
        expect(fn () => stApprover()->reject($stranger, $result))->toThrow(AuthorizationException::class);
        expect(fn () => stApprover()->sendToRecount($stranger, collect([$result]), $other['keeperB'], null))->toThrow(AuthorizationException::class);
    }

    stApprover()->approve($ctx['admin'], $result);
    expect(fn () => stApprover()->recheck($other['owner'], $result->fresh()))->toThrow(AuthorizationException::class);

    // And their counter cannot be given a recount here.
    $fresh = stProduct($ctx, 'Sugar', 10);
    stMap($ctx, $aisle, $fresh);
    $task2 = stPlanner()->assignCount($ctx['admin'], $st->fresh(), [$aisle->id], $ctx['keeperB'], null);
    stCountAndHandIn($ctx, $task2, $ctx['keeperB'], [[$aisle, $fresh, 3]]);

    expect(fn () => stApprover()->sendToRecount($ctx['admin'], collect([stResultFor($task2, $fresh)]), $other['keeperA'], null))
        ->toThrow(ValidationException::class);
});
