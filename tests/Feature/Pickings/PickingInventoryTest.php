<?php

use App\Actions\Pickings\ReleaseToPickerAction;
use App\Actions\Pickings\ReturnFromPickerAction;
use App\Models\Store;
use App\Services\Pickings\PickingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('what is out with pickers is counted as owned, separately from the shelf', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 4]],
    );

    $onTrust = PickingLedger::heldTotals($vendor->id);

    // Six on the shelf, four in somebody else's shop, ten owned in all.
    expect(shelfQuantity($product, $store))->toBe(6)
        ->and($onTrust['units'])->toBe(4)
        ->and($onTrust['value'])->toBe(4000.0);
});

test('the owned total is per branch when a branch is chosen', function () {
    $vendor = pickingVendor();
    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $mainProduct = pickingProduct($vendor, $main, 20, price: 1000);
    $branchProduct = pickingProduct($vendor, $branch, 20, price: 500);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Musa'), $main, [['product_id' => $mainProduct->id, 'quantity' => 3]],
    );
    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Chidi'), $branch, [['product_id' => $branchProduct->id, 'quantity' => 6]],
    );

    expect(PickingLedger::heldTotals($vendor->id, $main->id)['units'])->toBe(3)
        ->and(PickingLedger::heldTotals($vendor->id, $main->id)['value'])->toBe(3000.0)
        ->and(PickingLedger::heldTotals($vendor->id, $branch->id)['units'])->toBe(6)
        ->and(PickingLedger::heldTotals($vendor->id, $branch->id)['value'])->toBe(3000.0)
        ->and(PickingLedger::heldTotals($vendor->id)['units'])->toBe(9);
});

test('goods brought back stop counting as out with pickers', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 5]],
    );

    app(ReturnFromPickerAction::class)->execute($picking->items->first(), 5);

    expect(PickingLedger::heldTotals($vendor->id)['units'])->toBe(0)
        ->and(shelfQuantity($product, $store))->toBe(10);
});

test('a counter is told how many units are out, so a short shelf is not a shortage', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 3]],
    );

    // The shelf now holds 7 and the system says 7 — the count is honest either
    // way. The notice exists so the counter knows why the number moved.
    expect(PickingLedger::heldQuantityForProduct($product->id, $store->id))->toBe(3)
        ->and(shelfQuantity($product, $store))->toBe(7);
});

test('the drill-down names everyone holding the product, oldest trip first', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 30);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Musa Bala'), $store, [['product_id' => $product->id, 'quantity' => 2]],
        takenAt: now()->subDays(3),
    );
    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Chidi Okoro'), $store, [['product_id' => $product->id, 'quantity' => 5]],
        takenAt: now(),
    );

    $holders = PickingLedger::holdersOfProduct($product->id, $store->id);

    expect($holders)->toHaveCount(2)
        ->and($holders[0]['picker_name'])->toBe('Musa Bala')
        ->and($holders[0]['units'])->toBe(2)
        ->and($holders[1]['picker_name'])->toBe('Chidi Okoro')
        ->and($holders[0]['reference'])->toStartWith('GP-PICK-');
});
