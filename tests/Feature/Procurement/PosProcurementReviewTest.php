<?php

use App\Models\Procurement;
use App\Models\ProcurementItemCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/ReviewHelpers.php';

uses(RefreshDatabase::class);

/**
 * Receiving stock at the till.
 *
 * The till keeps every bit of the job it had — this is where the cartons
 * actually are, so it is where somebody counts them. What it loses is the one
 * thing it should never have had: a way for the person who booked a delivery
 * in to also sign for it, which until now the panel refused and the till did
 * not.
 */
test('the till lists deliveries waiting to be received', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    Sanctum::actingAs($ctx['checker']);

    $response = $this->getJson('/api/pos/procurements?vendor_id='.$ctx['vendor']->id)
        ->assertOk();

    $listed = $response->json('procurements.0');

    expect($listed['id'])->toBe($delivery->id)
        ->and($listed['status'])->toBe(Procurement::STATUS_PENDING)
        ->and($listed['items'][0]['quantity'])->toBe(12)
        ->and($listed['items'][0]['verified_quantity'])->toBe(12)
        ->and($listed['items'][0]['is_corrected'])->toBeFalse();
});

test('the till still receives stock, exactly as it did before', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    Sanctum::actingAs($ctx['checker']);

    $this->postJson("/api/pos/procurements/{$delivery->id}/approve", [
        'vendor_id' => $ctx['vendor']->id,
    ])->assertOk();

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(12);
});

test('the recorder cannot sign for their own delivery from the till either', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);

    // The gap this closes: the panel already refused this, the till did not.
    Sanctum::actingAs($ctx['keeper']);

    $this->postJson("/api/pos/procurements/{$delivery->id}/approve", [
        'vendor_id' => $ctx['vendor']->id,
    ])->assertStatus(403);

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_PENDING)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);
});

test('a correction can be recorded at the till, where the cartons are', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    Sanctum::actingAs($ctx['checker']);

    $this->postJson("/api/pos/procurements/{$delivery->id}/correct", [
        'vendor_id' => $ctx['vendor']->id,
        'lines'     => [$item->id => ['quantity' => 10]],
        'note'      => 'Two short',
    ])->assertOk()
        ->assertJsonPath('status', Procurement::STATUS_CHANGES_REQUESTED)
        ->assertJsonPath('awaiting_user_id', $ctx['keeper']->id);

    $correction = ProcurementItemCorrection::sole();

    expect((int) $correction->recorded_quantity)->toBe(12)
        ->and((int) $correction->verified_quantity)->toBe(10)
        ->and(reviewStock($product, $ctx['store']))->toBe(0);
});

test('a delivery sent back shows up for the person being waited on, not the other one', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    Sanctum::actingAs($ctx['checker']);
    $this->postJson("/api/pos/procurements/{$delivery->id}/correct", [
        'vendor_id' => $ctx['vendor']->id,
        'lines'     => [$item->id => ['quantity' => 10]],
    ])->assertOk();

    // The approver has done their part; it is not theirs to act on any more.
    $this->getJson('/api/pos/procurements?vendor_id='.$ctx['vendor']->id)
        ->assertOk()
        ->assertJsonCount(0, 'procurements');

    // The storekeeper is the one being waited on, so it is on their list.
    Sanctum::actingAs($ctx['keeper']);

    $response = $this->getJson('/api/pos/procurements?vendor_id='.$ctx['vendor']->id)
        ->assertOk();

    expect($response->json('procurements.0.status'))->toBe(Procurement::STATUS_CHANGES_REQUESTED)
        ->and($response->json('procurements.0.items.0.verified_quantity'))->toBe(10)
        ->and($response->json('procurements.0.items.0.quantity'))->toBe(12);
});

test('the recorder accepts from the till and the stock lands at the verified figure', function () {
    $ctx = reviewContext();
    $product = reviewProduct($ctx);
    $delivery = reviewDelivery($ctx, [[$product, 12, 5000]]);
    $item = $delivery->items->first();

    Sanctum::actingAs($ctx['checker']);
    $this->postJson("/api/pos/procurements/{$delivery->id}/correct", [
        'vendor_id' => $ctx['vendor']->id,
        'lines'     => [$item->id => ['quantity' => 10]],
    ])->assertOk();

    Sanctum::actingAs($ctx['keeper']);
    $this->postJson("/api/pos/procurements/{$delivery->id}/approve", [
        'vendor_id' => $ctx['vendor']->id,
    ])->assertOk();

    expect($delivery->fresh()->status)->toBe(Procurement::STATUS_APPROVED)
        ->and(reviewStock($product, $ctx['store']))->toBe(10);
});
