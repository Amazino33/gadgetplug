<?php

use App\Actions\Pickings\ReleaseToPickerAction;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickings\PickingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('the till lists what is still out at its own branch, grouped by picker', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 20, price: 1000);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Musa Bala'), $store, [['product_id' => $product->id, 'quantity' => 3]],
    );

    Sanctum::actingAs(User::find($vendor->user_id));

    $response = $this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)->assertOk();

    expect($response->json('pickers.0.name'))->toBe('Musa Bala')
        ->and($response->json('pickers.0.lines.0.held'))->toBe(3)
        // Cast: JSON writes 3000.0 as 3000, and the money value is the point.
        ->and((float) $response->json('pickers.0.lines.0.outstanding'))->toBe(3000.0);
});

test('another branch pickings are not on this till', function () {
    $vendor = pickingVendor();
    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);
    $branchProduct = pickingProduct($vendor, $branch, 20);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Branch Trader'), $branch, [['product_id' => $branchProduct->id, 'quantity' => 2]],
    );

    // The owner's till resolves to the default store, so the branch trip is
    // somebody else's business.
    Sanctum::actingAs(User::find($vendor->user_id));

    $response = $this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)->assertOk();

    expect($response->json('store_id'))->toBe($main->id)
        ->and($response->json('pickers'))->toBeEmpty();
});

test('a settled line disappears from the till list', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 20, price: 1000);
    $picker = pickingPicker($vendor);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    $line = $picking->items->first();

    Sanctum::actingAs(User::find($vendor->user_id));

    $this->postJson('/api/pos/pickings/payment', [
        'vendor_id' => $vendor->id,
        'picker_id' => $picker->id,
        'amount'    => 2000,
        'item_ids'  => [$line->id],
    ])->assertOk()->assertJson(['settled_units' => 2, 'change' => 0]);

    // assertOk matters: without it a 500 returns null for pickers, and an
    // empty expectation would pass on a broken endpoint.
    $after = $this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)->assertOk();

    expect($after->json('pickers'))->toBeEmpty();
});

test('the till is told what to hand back when the money overshoots', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 20, price: 1000);
    $picker = pickingPicker($vendor);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 1]],
    );

    Sanctum::actingAs(User::find($vendor->user_id));

    $this->postJson('/api/pos/pickings/payment', [
        'vendor_id' => $vendor->id,
        'picker_id' => $picker->id,
        'amount'    => 5000,
        'item_ids'  => [$picking->items->first()->id],
    ])->assertOk()->assertJson(['allocated' => 1000, 'change' => 4000, 'settled_units' => 1]);
});

test('a queued payment replayed twice settles once', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 20, price: 1000);
    $picker = pickingPicker($vendor);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 5]],
    );
    $line = $picking->items->first();

    Sanctum::actingAs(User::find($vendor->user_id));

    $payload = [
        'vendor_id' => $vendor->id,
        'picker_id' => $picker->id,
        'amount'    => 2000,
        'item_ids'  => [$line->id],
        'reference' => 'till-off-1',
    ];

    $this->postJson('/api/pos/pickings/payment', $payload)->assertOk()->assertJson(['duplicate' => false]);

    // The till retried because it never saw the first answer. Answered as a
    // duplicate rather than an error, so the queue stops instead of retrying.
    $this->postJson('/api/pos/pickings/payment', $payload)->assertOk()->assertJson(['duplicate' => true]);

    expect(PickingLedger::heldQuantity($line->fresh()))->toBe(3)
        ->and(PosSale::count())->toBe(1);
});

test('a picker from another vendor is refused', function () {
    $vendor = pickingVendor();
    $other = pickingVendor();
    $foreign = pickingPicker($other, 'Somebody Else');

    Sanctum::actingAs(User::find($vendor->user_id));

    $this->postJson('/api/pos/pickings/payment', [
        'vendor_id' => $vendor->id,
        'picker_id' => $foreign->id,
        'amount'    => 1000,
        'item_ids'  => [1],
    ])->assertNotFound();
});

test('the till can hand the cart over as a trip', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1650);
    $picker = pickingPicker($vendor, 'Musa Bala');

    Sanctum::actingAs(User::find($vendor->user_id));

    $this->postJson('/api/pos/pickings/release', [
        'vendor_id' => $vendor->id,
        'picker_id' => $picker->id,
        'items'     => [['product_id' => $product->id, 'quantity' => 3]],
    ])->assertOk()->assertJson(['picker' => 'Musa Bala', 'lines' => 1]);

    // Off the shelf, and now listed for payment on this same till.
    expect(shelfQuantity($product, $store))->toBe(7);

    $listed = $this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)->assertOk();

    expect($listed->json('pickers.0.lines.0.held'))->toBe(3);
});

test('a release the branch cannot cover leaves the shelf untouched', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 2);
    $picker = pickingPicker($vendor);

    Sanctum::actingAs(User::find($vendor->user_id));

    $this->postJson('/api/pos/pickings/release', [
        'vendor_id' => $vendor->id,
        'picker_id' => $picker->id,
        'items'     => [['product_id' => $product->id, 'quantity' => 5]],
    ])->assertStatus(422);

    expect(shelfQuantity($product, $store))->toBe(2)
        ->and(App\Models\Picking::count())->toBe(0);
});

test('a picker holding nothing can still be given goods from the till', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    // Two traders on the books, neither holding anything yet.
    pickingPicker($vendor, 'Chibuike');
    pickingPicker($vendor, 'Normal');

    Sanctum::actingAs(User::find($vendor->user_id));

    $response = $this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)->assertOk();

    // Nobody is holding goods, so the payment list is empty — but both are
    // offered for a first trip, which is the whole point of the release list.
    expect($response->json('pickers'))->toBeEmpty()
        ->and($response->json('available_pickers'))->toHaveCount(2)
        ->and(collect($response->json('available_pickers'))->pluck('name')->all())
            ->toContain('Chibuike', 'Normal');
});

test('a picker who has stopped trading is not offered goods', function () {
    $vendor = pickingVendor();
    pickingPicker($vendor, 'Still Here');
    pickingPicker($vendor, 'Gone Away')->update(['is_active' => false]);

    Sanctum::actingAs(User::find($vendor->user_id));

    $names = collect($this->getJson('/api/pos/pickings?vendor_id=' . $vendor->id)
        ->assertOk()
        ->json('available_pickers'))
        ->pluck('name')
        ->all();

    expect($names)->toContain('Still Here')
        ->and($names)->not->toContain('Gone Away');
});
