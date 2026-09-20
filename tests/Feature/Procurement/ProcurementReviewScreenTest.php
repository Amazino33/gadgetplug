<?php

use App\Filament\Vendor\Resources\Procurements\Pages\ViewProcurement;
use App\Models\Procurement;
use App\Models\ProcurementItemCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * The review screen, on the phone it is actually used on.
 *
 * Receiving stock happens standing next to the cartons, so these tests are
 * mostly about what the screen does NOT do: it does not put the figures in a
 * wide table, and it does not offer a button to somebody the workflow would
 * then refuse.
 */
function reviewScreen(array $ctx, Procurement $delivery)
{
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    return Livewire::test(ViewProcurement::class, ['record' => $delivery->getRouteKey()]);
}

test('the delivery renders as stacked cards, not a sideways-scrolling table', function () {
    $ctx = reviewContext();
    $one = reviewProduct($ctx, 'Oraimo Earbuds');
    $two = reviewProduct($ctx, 'Anker Cable');
    $delivery = reviewDelivery($ctx, [[$one, 12, 5000], [$two, 4, 2000]]);

    $this->actingAs($ctx['checker']);

    $page = reviewScreen($ctx, $delivery)->assertOk();

    $html = $page->html();

    // Every line is on screen, and so is the summary the approver decides on.
    expect($html)->toContain('Oraimo Earbuds')
        ->and($html)->toContain('Anker Cable')
        ->and($html)->toContain('Grand Total')
        ->and($html)->toContain('Total Qty')
        // The supplier heads the summary rather than being a column repeated
        // down a table.
        ->and($html)->toContain($ctx['supplier']->name);

    // The thing this replaced. The old markup wrapped a six-column table in
    // overflow-x-auto, which is exactly the sideways drag being designed out,
    // so its absence is the assertion worth making.
    //
    // Scoped to a delivery with no transport legs, deliberately: the legs
    // table is a separate, three-column panel that was never the problem and
    // is out of scope here. Add a leg to this fixture and these two lines
    // will fail on markup they were never about.
    expect($html)->not->toContain('overflow-x-auto')
        ->and($html)->not->toContain('<th');
});

test('the approver is offered approve and correct, the recorder is offered neither', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    $html = reviewScreen($ctx, $delivery)->assertOk()->html();

    expect($html)->toContain('mountAction(\'correctLine\'')
        ->and($html)->toContain('Approve &amp; Update Stock');

    // Whoever recorded it sees the delivery but is offered no way to wave it
    // through — the same rule the policy enforces, rendered.
    $this->actingAs($ctx['keeper']);
    $html = reviewScreen($ctx, $delivery)->assertOk()->html();

    expect($html)->toContain('Oraimo Earbuds')
        ->and($html)->not->toContain('mountAction(\'correctLine\'')
        ->and($html)->not->toContain('Approve &amp; Update Stock');
});

test('correcting a line from its own card sends the batch back', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);

    reviewScreen($ctx, $delivery)
        ->callAction('correctLine', arguments: ['item' => $item->id], data: [
            'quantity'        => 10,
            'unit_cost'       => 5000,
            'correction_note' => 'Two short on the pallet',
        ])
        ->assertHasNoActionErrors();

    $delivery->refresh();

    expect($delivery->status)->toBe(Procurement::STATUS_CHANGES_REQUESTED)
        ->and((int) $delivery->awaiting_user_id)->toBe($ctx['keeper']->id)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);

    $correction = ProcurementItemCorrection::sole();

    expect((int) $correction->recorded_quantity)->toBe(12)
        ->and((int) $correction->verified_quantity)->toBe(10);
});

test('a corrected card shows both figures and the variance together', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]], 'Two short');

    // The recorder re-checking has to see what they are being asked to agree
    // to: their figure, the other figure, and the gap between them.
    $this->actingAs($ctx['keeper']);
    $html = reviewScreen($ctx, $delivery->fresh())->assertOk()->html();

    expect($html)->toContain('Corrected')
        ->and($html)->toContain('(-2)')
        ->and($html)->toContain('Two short')
        ->and($html)->toContain($ctx['checker']->name)
        // Sent back to the person now looking at it, said in those words.
        ->and($html)->toContain('Sent back to you');
});

test('the recorder can agree from the re-check screen and the stock lands', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    $this->actingAs($ctx['keeper']);

    reviewScreen($ctx, $delivery->fresh())
        ->callAction('approve')
        ->assertHasNoActionErrors();

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(10);
});

test('the recorder can counter-correct from the re-check screen instead of agreeing', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->correct($delivery, $ctx['checker'], [$item->id => ['quantity' => 10]]);

    $this->actingAs($ctx['keeper']);

    reviewScreen($ctx, $delivery->fresh())
        ->callAction('correctLine', arguments: ['item' => $item->id], data: [
            'quantity'  => 11,
            'unit_cost' => 5000,
        ])
        ->assertHasNoActionErrors();

    $delivery->refresh();

    // Back to the same approver, and still nothing on the shelf.
    expect($delivery->status)->toBe(Procurement::STATUS_CHANGES_REQUESTED)
        ->and((int) $delivery->awaiting_user_id)->toBe($ctx['checker']->id)
        ->and(ProcurementItemCorrection::count())->toBe(2)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);
});

test('a second line can be corrected in the same review pass', function () {
    $ctx = reviewContext();
    $one = reviewProduct($ctx, 'Line One');
    $two = reviewProduct($ctx, 'Line Two');
    $delivery = reviewDelivery($ctx, [[$one, 12, 5000], [$two, 4, 2000]]);

    $firstLine  = $delivery->items->firstWhere('product_id', $one->id);
    $secondLine = $delivery->items->firstWhere('product_id', $two->id);

    $this->actingAs($ctx['checker']);

    $page = reviewScreen($ctx, $delivery);

    $page->callAction('correctLine', arguments: ['item' => $firstLine->id], data: [
        'quantity' => 10, 'unit_cost' => 5000,
    ])->assertHasNoActionErrors();

    // Somebody walking a pallet marks one line short, then another. The first
    // correction already handed the batch over, so without the allowance for a
    // review pass still in progress this second one would be refused.
    $page->callAction('correctLine', arguments: ['item' => $secondLine->id], data: [
        'quantity' => 3, 'unit_cost' => 2000,
    ])->assertHasNoActionErrors();

    expect(ProcurementItemCorrection::count())->toBe(2)
        ->and((int) $delivery->fresh()->awaiting_user_id)->toBe($ctx['keeper']->id);

    // And the agreed figures are what land.
    $this->actingAs($ctx['keeper']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->approve($delivery->fresh(), $ctx['keeper'], $ctx['store']->id);

    expect(reviewStock($one, $ctx['store']))->toBe(10)
        ->and(reviewStock($two, $ctx['store']))->toBe(3);
});

test('several lines can be corrected at once from the batch form', function () {
    $ctx = reviewContext();
    $one = reviewProduct($ctx, 'Line One');
    $two = reviewProduct($ctx, 'Line Two');
    $delivery = reviewDelivery($ctx, [[$one, 12, 5000], [$two, 4, 2000]]);

    $firstLine  = $delivery->items->firstWhere('product_id', $one->id);
    $secondLine = $delivery->items->firstWhere('product_id', $two->id);

    $this->actingAs($ctx['checker']);

    // The header action, kept beside the per-line buttons for checking a
    // delivery off against a waybill at a desk rather than off a shelf.
    reviewScreen($ctx, $delivery)
        ->callAction('correct', data: [
            'lines' => [
                $firstLine->id  => ['quantity' => 10, 'unit_cost' => 5000],
                $secondLine->id => ['quantity' => 4, 'unit_cost' => 2500],
            ],
            'correction_note' => 'Checked against the waybill',
        ])
        ->assertHasNoActionErrors();

    expect(ProcurementItemCorrection::count())->toBe(2)
        ->and((int) $delivery->fresh()->awaiting_user_id)->toBe($ctx['keeper']->id);

    // One line short, the other dearer than invoiced — both kept beside what
    // was recorded.
    expect($firstLine->fresh()->verifiedQuantity())->toBe(10)
        ->and($secondLine->fresh()->verifiedUnitCost())->toBe(2500.0)
        ->and($secondLine->fresh()->verifiedQuantity())->toBe(4);
});

test('an approved delivery offers no further actions', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->approve($delivery, $ctx['checker'], $ctx['store']->id);

    $html = reviewScreen($ctx, $delivery->fresh())->assertOk()->html();

    expect($html)->not->toContain('mountAction(\'correctLine\'')
        ->and($html)->toContain('Approved');
});
