<?php

use App\Actions\Pickings\ReleaseToPickerAction;
use App\Actions\Pickings\ReturnFromPickerAction;
use App\Models\PickingLedgerEntry;
use App\Models\Store;
use App\Services\Pickings\PickingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

test('what a picker holds is the sum of the ledger, never a stored number', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 20);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 10]],
    );
    $item = $picking->items->first();

    app(ReturnFromPickerAction::class)->execute($item, 2);

    // A payment settling 3 units, and a write-off of 1.
    PickingLedgerEntry::create([
        'vendor_id' => $vendor->id, 'picking_item_id' => $item->id,
        'direction' => PickingLedgerEntry::DIRECTION_PAYMENT,
        'quantity' => 3, 'amount' => 3000, 'unit_price' => 1000,
    ]);
    PickingLedgerEntry::create([
        'vendor_id' => $vendor->id, 'picking_item_id' => $item->id,
        'direction' => PickingLedgerEntry::DIRECTION_WRITEOFF,
        'quantity' => 1, 'amount' => 0,
    ]);

    // 10 taken, less 2 back, 3 paid, 1 forgiven.
    expect(PickingLedger::heldQuantity($item->fresh()))->toBe(4);
});

test('money that has not completed a unit sits against the line', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 5, price: 10000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    $item = $picking->items->first();

    // N15,000 against a N10,000 phone: one settled, N5,000 left over.
    PickingLedgerEntry::create([
        'vendor_id' => $vendor->id, 'picking_item_id' => $item->id,
        'direction' => PickingLedgerEntry::DIRECTION_PAYMENT,
        'quantity' => 1, 'amount' => 15000, 'unit_price' => 10000,
    ]);

    expect(PickingLedger::creditOnItem($item))->toBe(5000.0)
        // The second unit is not paid off until the rest arrives.
        ->and(PickingLedger::heldQuantity($item->fresh()))->toBe(1);
});

test('the on-trust figure counts only what is still out, per branch', function () {
    $vendor = pickingVendor();
    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $product = pickingProduct($vendor, $main, 20);
    $branchProduct = pickingProduct($vendor, $branch, 20);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $main, [['product_id' => $product->id, 'quantity' => 6]],
    );
    app(ReturnFromPickerAction::class)->execute($picking->items->first(), 2);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Chidi'), $branch, [['product_id' => $branchProduct->id, 'quantity' => 5]],
    );

    expect(PickingLedger::heldQuantityForProduct($product->id, $main->id))->toBe(4)
        ->and(PickingLedger::heldQuantityForProduct($product->id, $branch->id))->toBe(0)
        ->and(PickingLedger::heldQuantityForProduct($branchProduct->id, $branch->id))->toBe(5)
        // Across every branch when none is named.
        ->and(PickingLedger::heldQuantityForProduct($product->id))->toBe(4);
});

test('every picker still holding something is listed, worth what it costs today', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 50, price: 1000);

    $musa = pickingPicker($vendor, 'Musa Bala');
    $chidi = pickingPicker($vendor, 'Chidi Okoro');

    app(ReleaseToPickerAction::class)->execute($musa, $store, [['product_id' => $product->id, 'quantity' => 3]]);
    app(ReleaseToPickerAction::class)->execute($chidi, $store, [['product_id' => $product->id, 'quantity' => 7]]);

    $rows = PickingLedger::outstandingByPicker($vendor->id);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['picker_name'])->toBe('Chidi Okoro')
        ->and($rows[0]['units'])->toBe(7)
        ->and($rows[0]['value'])->toBe(7000.0)
        ->and($rows[1]['units'])->toBe(3);
});

test('raising the price raises what the picker owes, because that is the deal', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1000);
    $picker = pickingPicker($vendor);

    app(ReleaseToPickerAction::class)->execute($picker, $store, [['product_id' => $product->id, 'quantity' => 4]]);

    expect(PickingLedger::outstandingByPicker($vendor->id)[0]['value'])->toBe(4000.0);

    // They pay whatever it costs on the day they pay, so the value out with
    // them moves with the price. Deliberate, and the reason every payment
    // records the price it settled at.
    $product->update(['price' => 1500]);

    expect(PickingLedger::outstandingByPicker($vendor->id)[0]['value'])->toBe(6000.0);
});

test('a picker holding nothing drops off the list', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    app(ReturnFromPickerAction::class)->execute($picking->items->first(), 2);

    expect(PickingLedger::outstandingByPicker($vendor->id))->toBeEmpty();
});

test('who is holding a product can be listed for the drill-down', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 30);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Musa Bala'), $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Chidi Okoro'), $store, [['product_id' => $product->id, 'quantity' => 5]],
    );

    $holders = PickingLedger::holdersOfProduct($product->id, $store->id);

    expect($holders)->toHaveCount(2)
        ->and($holders->sum('units'))->toBe(7)
        ->and($holders->pluck('picker_name')->all())->toContain('Musa Bala', 'Chidi Okoro');
});
