<?php

use App\Actions\Pickings\RecordPickingPaymentAction;
use App\Actions\Pickings\ReleaseToPickerAction;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Services\Pickings\PickingLedger;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('money fills the ticked lines in order and the rest waits on the next one', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);

    $a = pickingProduct($vendor, $store, 5, price: 6000);
    $b = pickingProduct($vendor, $store, 5, price: 10000);
    $c = pickingProduct($vendor, $store, 5, price: 4000);

    $picking = app(ReleaseToPickerAction::class)->execute($picker, $store, [
        ['product_id' => $a->id, 'quantity' => 1],
        ['product_id' => $b->id, 'quantity' => 1],
        ['product_id' => $c->id, 'quantity' => 1],
    ]);

    [$lineA, $lineB, $lineC] = $picking->items->all();

    // The owner's own example: N15,000 across a 6,000, a 10,000 and a 4,000.
    $result = app(RecordPickingPaymentAction::class)->execute(
        picker: $picker,
        store: $store,
        amount: 15000,
        itemIds: [$lineA->id, $lineB->id, $lineC->id],
        userId: $vendor->user_id,
    );

    // a and c are settled outright; b takes the remaining 5,000 and stays out.
    expect(PickingLedger::heldQuantity($lineA->fresh()))->toBe(0)
        ->and(PickingLedger::heldQuantity($lineC->fresh()))->toBe(0)
        ->and(PickingLedger::heldQuantity($lineB->fresh()))->toBe(1)
        ->and(PickingLedger::creditOnItem($lineB->fresh()))->toBe(5000.0)
        ->and($result['unallocated'])->toBe(0.0)
        ->and($result['settled_units'])->toBe(2);
});

test('a part-paid unit is not sold until the rest of the money arrives', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 5, price: 10000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 1]],
    );
    $line = $picking->items->first();

    app(RecordPickingPaymentAction::class)->execute($picker, $store, 5000, [$line->id], userId: $vendor->user_id);

    // Nothing sold: half a phone is not a sale.
    expect(PickingLedger::heldQuantity($line->fresh()))->toBe(1)
        ->and(PosSale::count())->toBe(0);

    app(RecordPickingPaymentAction::class)->execute($picker, $store, 5000, [$line->id], userId: $vendor->user_id);

    expect(PickingLedger::heldQuantity($line->fresh()))->toBe(0)
        ->and(PosSale::count())->toBe(1)
        ->and((float) PosSale::first()->total)->toBe(10000.0);
});

test('a settled unit reaches the sales report without moving stock again', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 10, cost: 600, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 4]],
    );
    $line = $picking->items->first();

    // Six left on the shelf when the goods went out.
    expect(shelfQuantity($product, $store))->toBe(6);

    app(RecordPickingPaymentAction::class)->execute($picker, $store, 3000, [$line->id], userId: $vendor->user_id);

    $reports = app(SalesReportService::class);
    $summary = $reports->summary($vendor->id, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());

    expect($summary['revenue'])->toBe(3000.0)
        // The units left the shelf when they were picked, not now.
        ->and(shelfQuantity($product, $store))->toBe(6)
        // Profit uses what those exact units cost when they left.
        ->and($summary['cost'])->toBe(1800.0)
        ->and($summary['profit'])->toBe(1200.0);
});

test('the price on the day of payment is what is charged, and is recorded', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    $line = $picking->items->first();

    $product->update(['price' => 1500]);

    app(RecordPickingPaymentAction::class)->execute($picker, $store, 1500, [$line->id], userId: $vendor->user_id);

    expect(PickingLedger::heldQuantity($line->fresh()))->toBe(1)
        ->and((float) PosSaleItem::first()->unit_price)->toBe(1500.0);
});

test('money the ticked lines cannot use is handed back, not recorded', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 2]],
    );
    $line = $picking->items->first();

    $result = app(RecordPickingPaymentAction::class)->execute($picker, $store, 5000, [$line->id], userId: $vendor->user_id);

    // The line only owed 2,000. The other 3,000 is the picker's.
    expect($result['allocated'])->toBe(2000.0)
        ->and($result['unallocated'])->toBe(3000.0)
        ->and(PickingLedger::heldQuantity($line->fresh()))->toBe(0)
        ->and((float) PosSale::first()->total)->toBe(2000.0);
});

test('the same offline payment cannot be applied twice', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 5]],
    );
    $line = $picking->items->first();

    app(RecordPickingPaymentAction::class)->execute(
        $picker, $store, 2000, [$line->id], userId: $vendor->user_id, reference: 'off-1',
    );

    $again = app(RecordPickingPaymentAction::class)->execute(
        $picker, $store, 2000, [$line->id], userId: $vendor->user_id, reference: 'off-1',
    );

    expect($again['duplicate'])->toBeTrue()
        ->and(PickingLedger::heldQuantity($line->fresh()))->toBe(3)
        ->and(PosSale::count())->toBe(1);
});

test('a payment cannot settle another picker line', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $mine = pickingPicker($vendor, 'Musa');
    $theirs = pickingPicker($vendor, 'Chidi');

    $picking = app(ReleaseToPickerAction::class)->execute(
        $theirs, $store, [['product_id' => $product->id, 'quantity' => 2]],
    );

    expect(fn () => app(RecordPickingPaymentAction::class)->execute(
        $mine, $store, 1000, [$picking->items->first()->id], userId: $vendor->user_id,
    ))->toThrow(RuntimeException::class, 'not this picker');
});

test('paying for a line already settled changes nothing and takes no money', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $picker = pickingPicker($vendor);
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        $picker, $store, [['product_id' => $product->id, 'quantity' => 1]],
    );
    $line = $picking->items->first();

    app(RecordPickingPaymentAction::class)->execute($picker, $store, 1000, [$line->id], userId: $vendor->user_id);

    $again = app(RecordPickingPaymentAction::class)->execute($picker, $store, 1000, [$line->id], userId: $vendor->user_id);

    expect($again['allocated'])->toBe(0.0)
        ->and($again['unallocated'])->toBe(1000.0)
        ->and($again['sale'])->toBeNull()
        ->and(PosSale::count())->toBe(1);
});
