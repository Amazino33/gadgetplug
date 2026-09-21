<?php

// Shared fixtures for the store account close tests.
//
// Separate from Helpers.php for the reason that file already explains: Pest
// loads every test file into one global function namespace, so a helper only
// declared in a sibling test vanishes when that file is run on its own.

use App\Actions\Cash\CloseStoreAccountAction;
use App\Actions\Inventory\RecordPhysicalCountAction;
use App\Models\Category;
use App\Models\PhysicalStockCount;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\StoreAccountClose;
use App\Models\User;
use Carbon\CarbonInterface;

require_once __DIR__ . '/Helpers.php';

/**
 * A branch with one product on the shelf and an owner who can close it.
 */
function closeContext(int $onShelf = 10): array
{
    $vendor  = cashVendor();
    $store   = $vendor->defaultStore;
    $owner   = User::find($vendor->user_id);
    $cashier = User::factory()->create();

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store->id,
        'category_id'    => Category::create(['name' => 'C' . uniqid()])->id,
        'name'           => 'Closed Phone',
        'price'          => 100000,
        'cost_price'     => 60000,
        'stock_quantity' => $onShelf,
        'reserved_stock' => 0,
        'status'         => 'published',
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $onShelf, 'reserved' => 0],
    );

    return compact('vendor', 'store', 'owner', 'cashier', 'product');
}

/** A recorded count of one product at this branch. */
function closeCount(array $ctx, int $found, ?Store $store = null): PhysicalStockCount
{
    return app(RecordPhysicalCountAction::class)->execute(
        countedBy:   $ctx['owner'],
        store:       $store ?? $ctx['store'],
        periodStart: now()->subWeek(),
        periodEnd:   now(),
        counts:      [$ctx['product']->id => $found],
    );
}

/**
 * A stand-in for what the close computation service will hand over in Phase 2.
 *
 * Shaped to the paths CloseStoreAccountAction lifts into columns, so these
 * tests exercise the contract between the two rather than a convenient fiction.
 */
function closeFigures(array $over = []): array
{
    return array_replace_recursive([
        'balance' => [
            'value_sold'      => 100000.0,
            'submitted_total' => 100000.0,
            'till_expenses'   => 0.0,
            'period_debt'     => 0.0,
            'shortage'        => 0.0,
        ],
        'count_variance' => [
            'at_selling' => 0.0,
        ],
    ], $over);
}

function closePeriod(
    array $ctx,
    PhysicalStockCount $closingCount,
    ?CarbonInterface $from = null,
    ?CarbonInterface $to = null,
    ?PhysicalStockCount $openingCount = null,
    array $figures = [],
): StoreAccountClose {
    return app(CloseStoreAccountAction::class)->execute(
        closedBy:     $ctx['owner'],
        store:        $ctx['store'],
        from:         $from ?? now()->subMonth(),
        to:           $to ?? now(),
        closingCount: $closingCount,
        figures:      closeFigures($figures),
        openingCount: $openingCount,
    );
}

/**
 * A completed sale on a given tender.
 *
 * Debt and split sales also get their tender rows, because that is what the
 * till writes and what every downstream reader sums — a fixture that skipped
 * them would be testing a shape production never produces.
 */
function closeSale(array $ctx, string $method = 'cash', float $total = 100000, array $over = []): App\Models\PosSale
{
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, array_merge([
        'total'           => $total,
        'subtotal'        => $total,
        'payment_method'  => $method,
        'amount_tendered' => $method === 'cash' ? $total : 0,
        'change_given'    => 0,
    ], $over));

    if ($method === 'debt') {
        App\Models\PosSalePayment::create([
            'pos_sale_id' => $sale->id,
            'method'      => 'debt',
            'amount'      => $total,
        ]);
    }

    return $sale;
}

/** A split sale, with one tender row per leg exactly as the till writes it. */
function closeSplitSale(array $ctx, float $cash, float $card): App\Models\PosSale
{
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, [
        'total'           => $cash + $card,
        'subtotal'        => $cash + $card,
        'payment_method'  => 'split',
        'amount_tendered' => $cash + $card,
        'change_given'    => 0,
    ]);

    foreach ([['cash', $cash], ['card', $card]] as [$method, $amount]) {
        App\Models\PosSalePayment::create([
            'pos_sale_id' => $sale->id,
            'method'      => $method,
            'amount'      => $amount,
        ]);
    }

    return $sale;
}

/**
 * Hand cash over and have it acknowledged, which is what puts it on the right.
 *
 * A reason is always passed: SubmitCashAction refuses a handover that differs
 * from what the drawer expects without one, and several of these fixtures hand
 * over deliberately short.
 */
function closeRemit(array $ctx, float $amount, string $reason = 'Fixture handover'): void
{
    $submission = app(App\Actions\Cash\SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'],
        receiver:  $ctx['owner'],
        store:     $ctx['store'],
        amount:    $amount,
        reason:    $reason,
    );

    app(App\Actions\Cash\ResolveCashSubmissionAction::class)->confirm($submission, $ctx['owner']);
}

/** A stock movement the branch made that was neither a sale nor a delivery. */
function closeMovement(array $ctx, string $type, int $change): void
{
    App\Models\InventoryLedger::create([
        'vendor_id'        => $ctx['vendor']->id,
        'store_id'         => $ctx['store']->id,
        'product_id'       => $ctx['product']->id,
        'transaction_type' => $type,
        'quantity_change'  => $change,
    ]);
}

/** The live close view, over a window wide enough to hold everything just written. */
function closeBalance(
    array $ctx,
    ?App\Models\PhysicalStockCount $closingCount = null,
    ?App\Models\PhysicalStockCount $openingCount = null,
): array {
    return app(App\Services\Cash\StoreAccountCloseBalance::class)->for(
        store:        $ctx['store'],
        from:         now()->subMonth(),
        to:           now()->addDay(),
        closingCount: $closingCount,
        openingCount: $openingCount,
    );
}

/**
 * A finished two-person inventory count, exactly as the Inventory Count page
 * leaves one: a completed session with an audit line per product.
 *
 * @param  array<int, array{counted: ?int, system: int, status?: string, override?: int}>  $lines
 */
function closeAuditSession(array $ctx, array $lines, array $over = []): App\Models\BlindCountSession
{
    $session = App\Models\BlindCountSession::create(array_merge([
        'vendor_id'        => $ctx['vendor']->id,
        'store_id'         => $ctx['store']->id,
        'status'           => 'completed',
        'frequency'        => 'daily',
        'product_order'    => array_keys($lines),
        'storekeeper_a_id' => $ctx['cashier']->id,
        'storekeeper_b_id' => $ctx['owner']->id,
    ], $over));

    foreach ($lines as $productId => $line) {
        App\Models\AuditSession::create([
            'vendor_id'              => $ctx['vendor']->id,
            'blind_count_session_id' => $session->id,
            'product_id'             => $productId,
            'system_quantity'        => $line['system'],
            'storekeeper_a_id'       => $ctx['cashier']->id,
            'storekeeper_b_id'       => $ctx['owner']->id,
            'count_a'                => $line['counted'] ?? 0,
            'count_b'                => $line['counted'] ?? 0,
            'manager_override_count' => $line['override'] ?? null,
            'status'                 => $line['status'] ?? 'verified',
        ]);
    }

    return $session;
}
