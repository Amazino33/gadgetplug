<?php

use App\Models\CashSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('the till tells a cashier what they are holding and who can take it', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create(['name' => 'Chioma']);
    $vendor->users()->syncWithoutDetaching([$chioma->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');

    cashSale($vendor, $store, $chioma->id, ['total' => 70000]);

    Sanctum::actingAs($chioma);

    $response = $this->getJson('/api/pos/cash?vendor_id=' . $vendor->id)->assertOk();

    expect((float) $response->json('expected'))->toBe(70000.0)
        // The owner can receive; the cashier is never offered themselves.
        ->and($response->json('receivers'))->toHaveKey((string) $vendor->user_id)
        ->and($response->json('receivers'))->not->toHaveKey((string) $chioma->id);
});

test('a handover recorded at the till waits on the receiver', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$chioma->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');

    cashSale($vendor, $store, $chioma->id, ['total' => 70000]);

    Sanctum::actingAs($chioma);

    $this->postJson('/api/pos/cash/submit', [
        'vendor_id'   => $vendor->id,
        'received_by' => $vendor->user_id,
        'amount'      => 70000,
    ])->assertOk()->assertJson(['amount' => 70000, 'variance' => 0]);

    expect(CashSubmission::first()->status)->toBe(CashSubmission::STATUS_PENDING);

    // Handed over, so the till now says nothing is being held.
    expect((float) $this->getJson('/api/pos/cash?vendor_id=' . $vendor->id)->json('expected'))->toBe(0.0);
});

test('a short handover from the till needs its reason', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$chioma->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');

    cashSale($vendor, $store, $chioma->id, ['total' => 70000]);

    Sanctum::actingAs($chioma);

    $this->postJson('/api/pos/cash/submit', [
        'vendor_id'   => $vendor->id,
        'received_by' => $vendor->user_id,
        'amount'      => 65000,
    ])->assertStatus(422);

    expect(CashSubmission::count())->toBe(0);

    $this->postJson('/api/pos/cash/submit', [
        'vendor_id'   => $vendor->id,
        'received_by' => $vendor->user_id,
        'amount'      => 65000,
        'reason'      => 'Paid the transport',
    ])->assertOk()->assertJson(['variance' => -5000]);
});

test('cash cannot be handed to somebody who may not receive it', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $bystander = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$chioma->id, $bystander->id]);
    cashRoles($vendor);
    $chioma->assignRole('storekeeper');
    // The bystander is on the team but has no cash permission at all.

    cashSale($vendor, $store, $chioma->id, ['total' => 70000]);

    Sanctum::actingAs($chioma);

    $this->postJson('/api/pos/cash/submit', [
        'vendor_id'   => $vendor->id,
        'received_by' => $bystander->id,
        'amount'      => 70000,
    ])->assertStatus(422)->assertJson(['message' => 'That person cannot receive cash.']);

    expect(CashSubmission::count())->toBe(0);
});
