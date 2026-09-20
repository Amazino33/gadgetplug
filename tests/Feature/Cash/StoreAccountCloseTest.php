<?php

use App\Models\StoreAccountClose;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/CloseHelpers.php';

uses(RefreshDatabase::class);

/**
 * Closing the books is the one place in this system where a period ends.
 *
 * Everything else is deliberately live and re-runnable. What these tests
 * protect is the pair of things a close is actually for: figures that do not
 * move after somebody signed them, and an opening stock baseline that carries
 * forward without anybody getting to choose it.
 */

test('the first close opens on the count somebody chose', function () {
    $ctx = closeContext();

    $opening = closeCount($ctx, 10);
    $closing = closeCount($ctx, 7);

    $close = closePeriod($ctx, $closing, openingCount: $opening);

    expect($close->opening_source)->toBe(StoreAccountClose::OPENING_SELECTED)
        ->and($close->opening_count_id)->toBe($opening->id)
        ->and($close->closing_count_id)->toBe($closing->id)
        ->and($close->previous_close_id)->toBeNull()
        ->and($close->isFirstClose())->toBeTrue()
        ->and($close->reference)->toStartWith('GP-CLOSE-')
        ->and($close->closed_by)->toBe($ctx['owner']->id);
});

test('a branch that has never held stock may open its first period on nothing', function () {
    $ctx = closeContext();

    $close = closePeriod($ctx, closeCount($ctx, 0));

    // A true opening of zero, not a missing answer - and it still says which
    // way it got there.
    expect($close->opening_source)->toBe(StoreAccountClose::OPENING_SELECTED)
        ->and($close->opening_count_id)->toBeNull()
        ->and($close->openingQuantities())->toBeEmpty();
});

test('the next close carries the previous closing count forward on its own', function () {
    $ctx = closeContext();

    $first = closePeriod(
        $ctx,
        closeCount($ctx, 7),
        openingCount: closeCount($ctx, 10),
        from: now()->subMonths(2),
        to: now()->subMonth(),
    );

    $second = closePeriod(
        $ctx,
        closeCount($ctx, 4),
        from: now()->subMonth(),
        to: now(),
    );

    expect($second->opening_source)->toBe(StoreAccountClose::OPENING_CARRIED)
        // Nobody chose this - it is the count the last period closed on.
        ->and($second->opening_count_id)->toBe($first->closing_count_id)
        ->and($second->previous_close_id)->toBe($first->id)
        ->and($second->isFirstClose())->toBeFalse();
});

test('once there is a prior close the opening cannot be chosen', function () {
    $ctx = closeContext();

    closePeriod(
        $ctx,
        closeCount($ctx, 7),
        openingCount: closeCount($ctx, 10),
        from: now()->subMonths(2),
        to: now()->subMonth(),
    );

    // Refused rather than quietly substituted. A caller passing an opening
    // believes it will be used, and the seam between two periods is exactly
    // where stock goes missing unnoticed.
    closePeriod(
        $ctx,
        closeCount($ctx, 4),
        from: now()->subMonth(),
        to: now(),
        openingCount: closeCount($ctx, 99),
    );
})->throws(RuntimeException::class, 'There is nothing to choose');

test('the opening to closing chain stays continuous across consecutive closes', function () {
    $ctx = closeContext();

    $openings = [10, 7, 4];
    $closes = [];

    foreach ([7, 4, 2] as $i => $found) {
        $closes[] = closePeriod(
            $ctx,
            closeCount($ctx, $found),
            from: now()->subMonths(3 - $i),
            to: now()->subMonths(2 - $i),
            openingCount: $i === 0 ? closeCount($ctx, 10) : null,
        );
    }

    // Every period opens on exactly what the one before it closed on, so the
    // units are traceable end to end rather than resetting each month.
    foreach ($closes as $i => $close) {
        expect($close->openingQuantities()[$ctx['product']->id])->toBe($openings[$i]);

        if ($i > 0) {
            expect($close->opening_count_id)->toBe($closes[$i - 1]->closing_count_id);
        }
    }
});

test('the opening baseline reads what was counted, never the system figure beside it', function () {
    $ctx = closeContext(onShelf: 10);

    // The shelf disagrees with the books: 10 on the system, 6 actually there.
    $opening = closeCount($ctx, 6);

    $close = closePeriod($ctx, closeCount($ctx, 3), openingCount: $opening);

    // Opening on 10 would carry the discrepancy forward as if it were stock,
    // and the next period would look short by four units it never had.
    expect($close->openingQuantities()[$ctx['product']->id])->toBe(6)
        ->and($opening->lines()->first()->system_quantity)->toBe(10);
});

test('the frozen figures do not move when later trade lands', function () {
    $ctx = closeContext();

    $close = closePeriod($ctx, closeCount($ctx, 7), figures: [
        'balance' => ['value_sold' => 250000.0, 'shortage' => 12000.0],
    ]);

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 90000]);

    // toEqual, not toBe: the payload round-trips through JSON, which renders
    // 250000.0 as an int.
    expect($close->fresh()->figure('balance.value_sold'))->toEqual(250000)
        ->and($close->fresh()->figure('balance.shortage'))->toEqual(12000)
        ->and((float) $close->fresh()->shortage)->toBe(12000.00);
});

test('the lifted columns are copies of the payload, not a second calculation', function () {
    $ctx = closeContext();

    $close = closePeriod($ctx, closeCount($ctx, 7), figures: [
        'balance' => [
            'value_sold'      => 250000.0,
            'submitted_total' => 200000.0,
            'till_expenses'   => 15000.0,
            'period_debt'     => 23000.0,
            'shortage'        => 12000.0,
        ],
        'count_variance' => ['at_selling' => 300000.0],
    ]);

    expect((float) $close->value_sold)->toBe(250000.00)
        ->and((float) $close->submitted_total)->toBe(200000.00)
        ->and((float) $close->till_expenses)->toBe(15000.00)
        ->and((float) $close->period_debt)->toBe(23000.00)
        ->and((float) $close->shortage)->toBe(12000.00)
        ->and((float) $close->variance_at_selling)->toBe(300000.00);
});

test('a close can never be edited', function () {
    $ctx = closeContext();
    $close = closePeriod($ctx, closeCount($ctx, 7));

    $close->update(['shortage' => 999]);
})->throws(LogicException::class, 'Close a fresh period rather than editing one');

test('a close can never be deleted', function () {
    $ctx = closeContext();
    $close = closePeriod($ctx, closeCount($ctx, 7));

    // The opening balance of every period after this one hangs off it.
    $close->delete();
})->throws(LogicException::class, 'Closes are never deleted');

test('a period cannot start before the branch was last closed up to', function () {
    $ctx = closeContext();

    closePeriod(
        $ctx,
        closeCount($ctx, 7),
        from: now()->subMonths(2),
        to: now()->subMonth(),
    );

    // Overlapping the closed period would let the same sale be settled twice.
    closePeriod(
        $ctx,
        closeCount($ctx, 4),
        from: now()->subMonths(2)->addDays(3),
        to: now(),
    );
})->throws(RuntimeException::class, 'A new period has to start there or later');

test('one count cannot close two periods', function () {
    $ctx = closeContext();

    $closing = closeCount($ctx, 7);

    closePeriod($ctx, $closing, from: now()->subMonths(2), to: now()->subMonth());

    // Reusing it would carry the same opening forward twice and hide a whole
    // period of movement.
    closePeriod($ctx, $closing, from: now()->subMonth(), to: now());
})->throws(RuntimeException::class, 'already closed a period');

test('a period cannot open and close on the same count', function () {
    $ctx = closeContext();
    $count = closeCount($ctx, 7);

    closePeriod($ctx, $count, openingCount: $count);
})->throws(RuntimeException::class, 'nothing would have moved between them');

test('a count taken at another branch cannot close this one', function () {
    $ctx = closeContext();

    $other = $ctx['vendor']->stores()->create(['name' => 'Second Branch', 'is_default' => false]);

    closePeriod($ctx, closeCount($ctx, 7, store: $other));
})->throws(RuntimeException::class, 'taken at another branch');

test('a period has to start before it ends', function () {
    $ctx = closeContext();

    closePeriod($ctx, closeCount($ctx, 7), from: now(), to: now()->subMonth());
})->throws(RuntimeException::class, 'A period has to start before it ends');
