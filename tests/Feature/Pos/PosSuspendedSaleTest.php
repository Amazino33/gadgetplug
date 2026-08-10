<?php

use App\Models\PosSuspendedSale;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

// A held sale must still be visible on any later request, from any cashier
// on the same till/vendor — the whole point of "suspend" is picking it back
// up later, for whoever gets to it. These guard the full round trip, that
// there's no cap on how many sales can be held at once, and the no-store
// headers that stop an intermediary (carrier data-saving proxies, in
// particular) from serving a stale/empty list back to a cashier who just
// suspended a sale.

function suspendedSaleVendor(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Suspend Test Store']);

    return compact('owner', 'vendor');
}

test('a suspended sale is visible in the list right after suspending', function () {
    $data = suspendedSaleVendor();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/suspended', [
        'vendor_id'   => $data['vendor']->id,
        'label'       => 'Hold 1',
        'customer_id' => null,
        'cart_data'   => ['items' => [['id' => 1, 'name' => 'Widget', 'price' => 500, 'qty' => 2]], 'customer' => null],
    ])->assertCreated();

    $this->getJson('/api/pos/suspended?vendor_id=' . $data['vendor']->id)
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.label', 'Hold 1');
});

test('there is no cap on how many sales can be held at once', function () {
    $data = suspendedSaleVendor();
    Sanctum::actingAs($data['owner']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/pos/suspended', [
            'vendor_id' => $data['vendor']->id,
            'label'     => "Hold {$i}",
            'cart_data' => ['items' => [], 'customer' => null],
        ])->assertCreated();
    }

    $this->getJson('/api/pos/suspended?vendor_id=' . $data['vendor']->id)
        ->assertOk()
        ->assertJsonCount(5);
});

test('a different cashier on the same vendor can still see a held sale', function () {
    $data = suspendedSaleVendor();

    $firstCashier = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$firstCashier->id]);

    Sanctum::actingAs($firstCashier);
    $this->postJson('/api/pos/suspended', [
        'vendor_id'   => $data['vendor']->id,
        'label'       => 'Hold 2',
        'customer_id' => null,
        'cart_data'   => ['items' => [['id' => 1, 'name' => 'Widget', 'price' => 500, 'qty' => 1]], 'customer' => null],
    ])->assertCreated();

    $secondCashier = User::factory()->create();
    $data['vendor']->users()->syncWithoutDetaching([$secondCashier->id]);

    Sanctum::actingAs($secondCashier);
    $this->getJson('/api/pos/suspended?vendor_id=' . $data['vendor']->id)
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.label', 'Hold 2');
});

test('suspended-sale list responses tell intermediaries never to cache them', function () {
    $data = suspendedSaleVendor();
    Sanctum::actingAs($data['owner']);

    $response = $this->getJson('/api/pos/suspended?vendor_id=' . $data['vendor']->id)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('resuming a held sale returns its cart and removes it from the list', function () {
    $data = suspendedSaleVendor();
    Sanctum::actingAs($data['owner']);

    $suspended = PosSuspendedSale::create([
        'vendor_id'  => $data['vendor']->id,
        'cashier_id' => $data['owner']->id,
        'label'      => 'Hold 1',
        'cart_data'  => ['items' => [['id' => 1, 'name' => 'Widget', 'price' => 500, 'qty' => 1]], 'customer' => null],
    ]);

    $this->postJson("/api/pos/suspended/{$suspended->id}/resume", ['vendor_id' => $data['vendor']->id])
        ->assertOk()
        ->assertJsonPath('cart_data.items.0.name', 'Widget');

    expect(PosSuspendedSale::find($suspended->id))->toBeNull();
});

test('a vendor cannot resume or clear another vendor\'s held sale', function () {
    $data      = suspendedSaleVendor();
    $otherData = suspendedSaleVendor();

    $suspended = PosSuspendedSale::create([
        'vendor_id'  => $otherData['vendor']->id,
        'cashier_id' => $otherData['owner']->id,
        'label'      => 'Someone else\'s hold',
        'cart_data'  => ['items' => [], 'customer' => null],
    ]);

    Sanctum::actingAs($data['owner']);

    $this->postJson("/api/pos/suspended/{$suspended->id}/resume", ['vendor_id' => $data['vendor']->id])
        ->assertNotFound();

    $this->deleteJson("/api/pos/suspended/{$suspended->id}", ['vendor_id' => $data['vendor']->id])
        ->assertNotFound();

    expect(PosSuspendedSale::find($suspended->id))->not->toBeNull();
});

test('clearing a held sale removes it without resuming it', function () {
    $data = suspendedSaleVendor();
    Sanctum::actingAs($data['owner']);

    $suspended = PosSuspendedSale::create([
        'vendor_id'  => $data['vendor']->id,
        'cashier_id' => $data['owner']->id,
        'label'      => 'Hold 1',
        'cart_data'  => ['items' => [], 'customer' => null],
    ]);

    $this->deleteJson("/api/pos/suspended/{$suspended->id}", ['vendor_id' => $data['vendor']->id])
        ->assertOk();

    expect(PosSuspendedSale::find($suspended->id))->toBeNull();
});
