<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Pickings\ReleaseToPickerAction;
use App\Actions\Pickings\ReturnFromPickerAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Picker;
use App\Models\Picking;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pickings\PickingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

test('goods handed to a picker leave the shelf and are recorded as a trip', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);
    $picker = pickingPicker($vendor);

    $picking = app(ReleaseToPickerAction::class)->execute(
        picker: $picker,
        store: $store,
        lines: [['product_id' => $product->id, 'quantity' => 3]],
        userId: $vendor->user_id,
    );

    expect(shelfQuantity($product, $store))->toBe(7)
        ->and($picking->reference)->toStartWith('GP-PICK-')
        ->and($picking->store_id)->toBe($store->id)
        ->and($picking->released_by)->toBe($vendor->user_id)
        ->and($picking->items)->toHaveCount(1)
        // Still ours, still out: the ledger says the units moved, not that they sold.
        ->and(InventoryLedger::where('reference', $picking->reference)->value('transaction_type'))
            ->toBe('picking_out');
});

test('what the units actually cost is captured as they leave', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, cost: 600);
    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 3]],
    );

    // Drawn from the cost layers, so a sale months later books what these units
    // really cost rather than the cost price on the day the money arrives.
    expect((float) $picking->items->first()->unit_cost)->toBe(600.0);
});

test('a branch cannot hand out more than it is holding, and records nothing when it fails', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 2);
    $picker = pickingPicker($vendor);

    expect(fn () => app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 5]],
    ))->toThrow(Exception::class);

    expect(shelfQuantity($product, $store))->toBe(2)
        ->and(Picking::count())->toBe(0);
});

test('a trip is all or nothing across its lines', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $fine = pickingProduct($vendor, $store, 10);
    $short = pickingProduct($vendor, $store, 1);

    expect(fn () => app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [
            ['product_id' => $fine->id, 'quantity' => 2],
            ['product_id' => $short->id, 'quantity' => 4],
        ],
    ))->toThrow(Exception::class);

    // The line that could have gone did not: a trip half-recorded would leave
    // the picker holding goods nobody wrote down.
    expect(shelfQuantity($fine, $store))->toBe(10)
        ->and(Picking::count())->toBe(0);
});

test('another vendor product cannot be handed out', function () {
    $vendor = pickingVendor();
    $other = pickingVendor();
    $foreign = pickingProduct($other, $other->defaultStore, 10);

    expect(fn () => app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $vendor->defaultStore, [['product_id' => $foreign->id, 'quantity' => 1]],
    ))->toThrow(RuntimeException::class, 'does not belong to this vendor');
});

test('goods brought back go to the branch they left', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 4]],
    );
    $item = $picking->items->first();

    app(ReturnFromPickerAction::class)->execute($item, 3);

    expect(shelfQuantity($product, $store))->toBe(9)
        ->and(PickingLedger::heldQuantity($item->fresh()))->toBe(1)
        ->and(InventoryLedger::where('transaction_type', 'picking_return')->count())->toBe(1);
});

test('more cannot come back than went out', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    $item = $picking->items->first();

    expect(fn () => app(ReturnFromPickerAction::class)->execute($item, 3))
        ->toThrow(RuntimeException::class, 'Only 2 of that line is still out');

    expect(shelfQuantity($product, $store))->toBe(8);
});
