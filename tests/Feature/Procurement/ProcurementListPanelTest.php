<?php

use App\Filament\Vendor\Resources\Procurements\Pages\ListProcurements;
use App\Models\Procurement;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * The expanded row on the list.
 *
 * Expanding a delivery is something people do to find out what was in the
 * boxes, and the panel used to answer with four facts about the paperwork and
 * nothing about the goods. Checking a morning's deliveries in meant opening
 * each one, reading it, going back, opening the next.
 */
function procurementList(array $ctx)
{
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);

    return Livewire::test(ListProcurements::class);
}

test('the expanded row lists every item with its quantity and price', function () {
    $ctx = reviewContext();
    $one = reviewProduct($ctx, 'Oraimo FreePods 4');
    $two = reviewProduct($ctx, 'Anker 20W Charger');
    $delivery = reviewDelivery($ctx, [[$one, 12, 5000], [$two, 6, 3200]]);

    $this->actingAs($ctx['checker']);

    $html = procurementList($ctx)->assertOk()->html();

    expect($html)->toContain('Items purchased')
        ->and($html)->toContain('Oraimo FreePods 4')
        ->and($html)->toContain('Anker 20W Charger')
        // Quantity times unit cost, and the line total, per line.
        ->and($html)->toContain('12 &times; &#8358;5,000.00')
        ->and($html)->toContain('6 &times; &#8358;3,200.00')
        ->and($html)->toContain('&#8358;60,000.00')
        ->and($html)->toContain('&#8358;19,200.00');

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING);
});

test('a corrected line shows what it was as well as what it is', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx, 'Oraimo FreePods 4');
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->correct($delivery, $ctx['checker'], [$delivery->items->first()->id => ['quantity' => 10]]);

    $this->actingAs($ctx['keeper']);
    $html = procurementList($ctx)->assertOk()->html();

    expect($html)->toContain('10 &times; &#8358;5,000.00')
        ->and($html)->toContain('was 12 &times; &#8358;5,000.00');
});

test('the approve button is offered to the checker and withheld from the recorder', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    $html = procurementList($ctx)->assertOk()->html();

    expect($html)->toContain('approveFromList')
        ->and($html)->toContain('Approve &amp; Update Stock');

    // Whoever recorded it can read the lines but is offered no way to wave
    // them through — the same rule as the record screen, on the list.
    $this->actingAs($ctx['keeper']);
    $html = procurementList($ctx)->assertOk()->html();

    expect($html)->toContain('Oraimo Earbuds')
        ->and($html)->not->toContain('approveFromList');
});

test('approving from the list receives the stock', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);

    procurementList($ctx)
        ->callAction('approveFromList', arguments: ['record' => $delivery->id])
        ->assertHasNoActionErrors();

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(12);
});

test('approving from the list honours a correction', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    $this->actingAs($ctx['checker']);
    app(App\Services\Procurement\ProcurementReview::class)
        ->correct($delivery, $ctx['checker'], [$delivery->items->first()->id => ['quantity' => 10]]);

    // The recorder agrees from the list rather than from the record.
    $this->actingAs($ctx['keeper']);

    procurementList($ctx)
        ->callAction('approveFromList', arguments: ['record' => $delivery->id])
        ->assertHasNoActionErrors();

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(10);
});

test('the recorder cannot approve their own delivery from the list either', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    // Hiding the button is not the gate. Mounting the action directly, as
    // anyone can from a console, still has to be refused.
    $this->actingAs($ctx['keeper']);

    procurementList($ctx)
        ->callAction('approveFromList', arguments: ['record' => $delivery->id]);

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);
});

test('another vendor delivery cannot be approved by passing its id', function () {
    $ctx = reviewContext();
    $other = reviewContext();

    $product = reviewProduct($other);
    $delivery = reviewDelivery($other, [[$product, 12, 5000]]);

    // The id is resolved through the resource's scoped query, so it is not
    // found at all rather than found and then refused.
    $this->actingAs($ctx['checker']);

    procurementList($ctx)
        ->callAction('approveFromList', arguments: ['record' => $delivery->id]);

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING)
        ->and(reviewStock($product, $other['store']))->toBe(0);
});
