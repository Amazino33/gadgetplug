<?php

use App\Models\Procurement;
use App\Models\ProcurementItemCorrection;
use App\Models\StockCostLayer;
use App\Services\Procurement\ProcurementReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\UnauthorizedException;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * A delivery is agreed between two people or it is not agreed at all.
 *
 * The rule underneath every test here: stock does not exist until both sides
 * say the same thing. The storekeeper's figures are a claim, not a receipt —
 * so a batch nobody has checked has put nothing on any shelf, and the whole
 * argument happens before the books move rather than being corrected after.
 */
function review(): ProcurementReview
{
    return app(ProcurementReview::class);
}

test('a recorded delivery waits on somebody else and puts nothing on the shelf', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    expect($delivery->status)->toBe(Procurement::STATUS_PENDING)
        ->and($delivery->isOpen())->toBeTrue()
        ->and(reviewStock($product, $ctx['store']))->toBe(0)
        ->and(StockCostLayer::where('product_id', $product->id)->count())->toBe(0);
});

test('whoever recorded a delivery cannot approve it, even holding the permission', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['keeper']);

    // The rule the whole arrangement rests on. Note this person DOES hold
    // approve_procurement — they are refused for having recorded this
    // delivery, not for lacking the rights to receive one.
    expect(fn () => review()->approve($delivery, $ctx['keeper'], $ctx['store']->id))
        ->toThrow(UnauthorizedException::class, 'somebody else has to check it in');

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);
});

test('nor can they correct their own untouched delivery', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $item = $delivery->items->first();
    $this->actingAs($ctx['keeper']);

    // That is not a correction, it is an edit — and an editable delivery is
    // one nobody else's signature means anything on.
    expect(fn () => review()->correct($delivery, $ctx['keeper'], [$item->id => ['quantity' => 10]]))
        ->toThrow(UnauthorizedException::class);

    expect(ProcurementItemCorrection::count())->toBe(0);
});

test('approving with nothing corrected receives exactly what was recorded', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    $approved = review()->approve($delivery, $ctx['checker'], $ctx['store']->id);

    expect($approved->status)->toBe(Procurement::STATUS_APPROVED)
        ->and((int) $approved->approved_by)->toBe($ctx['checker']->id)
        ->and($approved->awaiting_user_id)->toBeNull()
        // Untouched figures, straight through: no correction, no variance,
        // nothing adjusted afterwards.
        ->and(reviewStock($product, $ctx['store']))->toBe(12)
        ->and((float) $product->fresh()->cost_price)->toBe(5000.0)
        ->and(ProcurementItemCorrection::count())->toBe(0);

    $layer = StockCostLayer::where('product_id', $product->id)->sole();

    expect((int) $layer->quantity_remaining)->toBe(12)
        ->and((float) $layer->unit_cost)->toBe(5000.0);
});

test('a correction keeps both figures rather than overwriting the recorded one', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);

    // The storekeeper wrote 12. The approver counted 10.
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]], 'Counted 10 on the pallet');

    $correction = ProcurementItemCorrection::sole();

    expect((int) $correction->recorded_quantity)->toBe(12)
        ->and((int) $correction->verified_quantity)->toBe(10)
        ->and($correction->quantityVariance())->toBe(-2)
        ->and((int) $correction->corrected_by)->toBe($ctx['checker']->id)
        ->and($correction->corrected_at)->not->toBeNull()
        ->and($correction->note)->toBe('Counted 10 on the pallet');

    // The line itself is untouched. What the storekeeper wrote stays written.
    expect((int) $item->fresh()->quantity)->toBe(12)
        ->and($item->fresh()->verifiedQuantity())->toBe(10);
});

test('a correction is never edited, only added to', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    $correction = ProcurementItemCorrection::sole();

    expect(fn () => $correction->update(['verified_quantity' => 11]))
        ->toThrow(LogicException::class, 'never edited');
});

test('correcting any one line hands the whole batch back to the recorder', function () {
    $ctx = reviewContext();
    $one = reviewProduct($ctx, 'Line One');
    $two = reviewProduct($ctx, 'Line Two');
    $delivery = reviewDelivery($ctx, [[$one, 12, 5000], [$two, 4, 2000]]);

    $firstLine = $delivery->items->firstWhere('product_id', $one->id);

    $this->actingAs($ctx['checker']);
    $after = review()->correct($delivery, $ctx['checker'], [$firstLine->id => ['quantity' => 10]]);

    // One line was questioned; the batch as a whole goes back. There is no
    // half-agreed delivery.
    expect($after->status)->toBe(Procurement::STATUS_CHANGES_REQUESTED)
        ->and((int) $after->awaiting_user_id)->toBe($ctx['keeper']->id)
        ->and($after->isAwaiting($ctx['keeper']))->toBeTrue()
        ->and(reviewStock($one, $ctx['store']))->toBe(0)
        ->and(reviewStock($two, $ctx['store']))->toBe(0);
});

test('the approver cannot approve while it is the recorder turn to answer', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    // Otherwise correcting then immediately approving would be a one-person
    // route to any number the approver liked.
    expect(fn () => review()->approve($delivery->fresh(), $ctx['checker'], $ctx['store']->id))
        ->toThrow(UnauthorizedException::class, 'back with the other party');

    expect(reviewStock($product, $ctx['store']))->toBe(0);
});

test('stock lands at the verified figures once the recorder accepts the correction', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    // Still nothing on the shelf while they disagree.
    expect(reviewStock($product, $ctx['store']))->toBe(0);

    $this->actingAs($ctx['keeper']);
    $agreed = review()->approve($delivery->fresh(), $ctx['keeper'], $ctx['store']->id);

    // 10, not 12, and not 12-then-adjusted-by-2: the shelf is written once,
    // correctly, because the argument finished before anything moved.
    expect($agreed->status)->toBe(Procurement::STATUS_APPROVED)
        ->and($agreed->awaiting_user_id)->toBeNull()
        ->and(reviewStock($product, $ctx['store']))->toBe(10);

    $layer = StockCostLayer::where('product_id', $product->id)->sole();

    expect((int) $layer->quantity_remaining)->toBe(10);

    // The money follows the agreed figures too.
    expect((float) $agreed->fresh()->total_cost)->toBe(50000.0);
});

test('the recorder can counter-correct, and it goes back to the same approver', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    // The storekeeper re-checks and says 11.
    $this->actingAs($ctx['keeper']);
    $after = review()->correct($delivery->fresh(), $ctx['keeper'], [$item->id => ['quantity' => 11]]);

    expect($after->status)->toBe(Procurement::STATUS_CHANGES_REQUESTED)
        ->and((int) $after->awaiting_user_id)->toBe($ctx['checker']->id)
        ->and(ProcurementItemCorrection::count())->toBe(2);

    // Both accounts survive, in the order they were given.
    $corrections = ProcurementItemCorrection::orderBy('id')->get();

    expect((int) $corrections[0]->verified_quantity)->toBe(10)
        ->and((int) $corrections[1]->verified_quantity)->toBe(11)
        // Variance is always measured against what was recorded at delivery,
        // not against the last counter-offer.
        ->and((int) $corrections[1]->recorded_quantity)->toBe(12)
        ->and($corrections[1]->quantityVariance())->toBe(-1);

    // The last word is what the line now means.
    expect($item->fresh()->verifiedQuantity())->toBe(11);

    // And the loop closes when the approver finally agrees.
    $this->actingAs($ctx['checker']);
    review()->approve($delivery->fresh(), $ctx['checker'], $ctx['store']->id);

    expect(reviewStock($product, $ctx['store']))->toBe(11);
});

test('a unit cost correction moves the cost basis and the total, not the units', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 10, 5000]]);
    $item = $delivery->items->first();

    // Invoiced at 5,000 but actually charged 5,500.
    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['unit_cost' => 5500]]);

    $correction = ProcurementItemCorrection::sole();

    expect($correction->unitCostVariance())->toBe(500.0)
        ->and($correction->quantityVariance())->toBe(0)
        ->and($correction->lineTotalVariance())->toBe(5000.0);

    $this->actingAs($ctx['keeper']);
    $agreed = review()->approve($delivery->fresh(), $ctx['keeper'], $ctx['store']->id);

    expect(reviewStock($product, $ctx['store']))->toBe(10)
        ->and((float) $product->fresh()->cost_price)->toBe(5500.0)
        ->and((float) $agreed->fresh()->total_cost)->toBe(55000.0);

    $layer = StockCostLayer::where('product_id', $product->id)->sole();

    expect((float) $layer->unit_cost)->toBe(5500.0);
});

test('variance is computed across both fields at once, not by adding them separately', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [
        $item->id => ['quantity' => 10, 'unit_cost' => 5500],
    ]);

    $item = $item->fresh();

    expect($item->quantityVariance())->toBe(-2)
        ->and($item->unitCostVariance())->toBe(500.0)
        ->and($item->lineTotal())->toBe(60000.0)
        ->and($item->verifiedLineTotal())->toBe(55000.0)
        // 55,000 - 60,000. Adding the two variances on their own would give
        // the wrong answer, because they multiply.
        ->and($item->lineTotalVariance())->toBe(-5000.0);
});

test('restating the same figures is refused rather than bouncing the batch for nothing', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);

    expect(fn () => review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 12]]))
        ->toThrow(RuntimeException::class, 'already on this delivery');

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING)
        ->and(ProcurementItemCorrection::count())->toBe(0);
});

test('a third person cannot join an argument between two', function () {
    $ctx = reviewContext();
    $outsider = reviewOutsider($ctx);
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    // No override, no escalation: strictly the two who are already on it.
    $this->actingAs($outsider);

    expect(fn () => review()->approve($delivery->fresh(), $outsider, $ctx['store']->id))
        ->toThrow(UnauthorizedException::class);

    expect(fn () => review()->correct($delivery->fresh(), $outsider, [$item->id => ['quantity' => 9]]))
        ->toThrow(UnauthorizedException::class);

    expect(reviewStock($product, $ctx['store']))->toBe(0);
});

test('an unclaimed delivery may still be picked up by any eligible approver', function () {
    $ctx = reviewContext();
    $outsider = reviewOutsider($ctx);
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    // Nobody has corrected anything yet, so the batch is not tied to a
    // counterparty and whoever is on shift may receive it.
    $this->actingAs($outsider);
    $approved = review()->approve($delivery, $outsider, $ctx['store']->id);

    expect($approved->status)->toBe(Procurement::STATUS_APPROVED)
        ->and((int) $approved->approved_by)->toBe($outsider->id);
});

test('a delivery already answered cannot be answered again', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    review()->approve($delivery, $ctx['checker'], $ctx['store']->id);

    expect(fn () => review()->approve($delivery->fresh(), $ctx['checker'], $ctx['store']->id))
        ->toThrow(UnauthorizedException::class, 'already been answered');

    expect(fn () => review()->correct($delivery->fresh(), $ctx['checker'], [$item->id => ['quantity' => 1]]))
        ->toThrow(UnauthorizedException::class, 'already been answered');

    // Approved once means received once.
    expect(reviewStock($product, $ctx['store']))->toBe(12);
});

test('a one-person shop can still receive its own deliveries', function () {
    $ctx = reviewContext();

    // Nobody but the owner holds approve_procurement, and the owner is the one
    // who recorded it. Refusing here would leave the stock never received.
    $product = reviewProduct($ctx);

    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $delivery->updateQuietly(['created_by' => $ctx['owner']->id]);

    // Strip every other approver so the owner really is alone. Both staff in
    // this fixture can approve, so removing one is not enough.
    foreach (['checker', 'keeper'] as $role) {
        $ctx[$role]->stores()->detach();
        $ctx['vendor']->users()->detach($ctx[$role]->id);
    }

    $this->actingAs($ctx['owner']);
    $approved = review()->approve($delivery->fresh(), $ctx['owner'], $ctx['store']->id);

    expect($approved->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(12);
});

test('somebody who may not receive at that branch is refused', function () {
    $ctx = reviewContext();

    // A second branch is what makes the branch rule bite at all — a one-branch
    // shop has no branches to keep apart.
    $other = App\Models\Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Uyo Branch']);

    $product = reviewProduct($ctx, 'Branch Stock', $other);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]], $other);

    // The checker is assigned to the default store, not to Uyo.
    $this->actingAs($ctx['checker']);

    expect(fn () => review()->approve($delivery, $ctx['checker'], $other->id))
        ->toThrow(UnauthorizedException::class, 'not permitted to receive deliveries');

    expect(reviewStock($product, $other))->toBe(0);
});
