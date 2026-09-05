<?php

use App\Actions\Cash\ResolveCashSubmissionAction;
use App\Actions\Cash\SubmitCashAction;
use App\Models\CashSubmission;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Cash\CashDrawer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function cashVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Cash Vendor '.uniqid(),
    ]);
}

/** A completed sale, exactly as the till writes one. */
function cashSale(Vendor $vendor, Store $store, int $cashierId, array $over = []): PosSale
{
    $total = $over['total'] ?? 10000;

    return PosSale::create(array_merge([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $vendor->id,
        'store_id'        => $store->id,
        'cashier_id'      => $cashierId,
        'subtotal'        => $total,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => $total,
        'payment_method'  => 'cash',
        'amount_tendered' => $total,
        'change_given'    => 0,
        'status'          => 'completed',
        'completed_at'    => now(),
    ], $over));
}

test('what a cashier should be holding is what they took, less what they handed over', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();

    cashSale($vendor, $store, $chioma->id, ['total' => 60000]);
    cashSale($vendor, $store, $chioma->id, ['total' => 85000]);

    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(145000.0);

    app(SubmitCashAction::class)->execute(
        submitter: $chioma,
        receiver: User::find($vendor->user_id),
        store: $store,
        amount: 145000,
    );

    // Handed over, so no longer theirs to account for.
    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(0.0);
});

test('only the cash that stayed in the drawer counts, not the sale total', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();

    // Paid with 20,000 for a 15,000 sale: only 15,000 stayed.
    cashSale($vendor, $store, $chioma->id, [
        'total' => 15000, 'amount_tendered' => 20000, 'change_given' => 5000,
    ]);

    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(15000.0);
});

test('a card sale puts nothing in the drawer, but the cash leg of a split does', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();

    cashSale($vendor, $store, $chioma->id, ['total' => 50000, 'payment_method' => 'card']);

    $split = cashSale($vendor, $store, $chioma->id, [
        'total' => 30000, 'payment_method' => 'split', 'amount_tendered' => 0, 'change_given' => 0,
    ]);
    PosSalePayment::create(['pos_sale_id' => $split->id, 'method' => 'cash', 'amount' => 12000]);
    PosSalePayment::create(['pos_sale_id' => $split->id, 'method' => 'card', 'amount' => 18000]);

    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(12000.0);
});

test('each cashier answers only for their own takings', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $ibrahim = User::factory()->create();

    cashSale($vendor, $store, $chioma->id, ['total' => 40000]);
    cashSale($vendor, $store, $ibrahim->id, ['total' => 25000]);

    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(40000.0)
        ->and(CashDrawer::expectedFrom($vendor->id, $store->id, $ibrahim->id))->toBe(25000.0);
});

test('a shortfall is recorded with its reason rather than refused', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();

    cashSale($vendor, $store, $chioma->id, ['total' => 145000]);

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $chioma,
        receiver: User::find($vendor->user_id),
        store: $store,
        amount: 142500,
        reason: 'Gave 2,500 to the driver for fuel',
    );

    expect($submission->variance())->toBe(-2500.0)
        ->and($submission->isShort())->toBeTrue()
        ->and($submission->expected_amount)->toEqual(145000.0)
        // The real money moved, so the balance falls by what was handed over,
        // not by what should have been.
        ->and(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(2500.0);
});

test('a mismatch with no reason is refused, so nothing is short by accident', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();

    cashSale($vendor, $store, $chioma->id, ['total' => 145000]);

    expect(fn () => app(SubmitCashAction::class)->execute(
        submitter: $chioma,
        receiver: User::find($vendor->user_id),
        store: $store,
        amount: 142500,
    ))->toThrow(RuntimeException::class, 'Say why');

    expect(CashSubmission::count())->toBe(0);
});

test('cash cannot be handed to yourself', function () {
    $vendor = cashVendor();
    $chioma = User::factory()->create();

    cashSale($vendor, $vendor->defaultStore, $chioma->id, ['total' => 1000]);

    expect(fn () => app(SubmitCashAction::class)->execute(
        submitter: $chioma, receiver: $chioma, store: $vendor->defaultStore, amount: 1000,
    ))->toThrow(RuntimeException::class, 'somebody else');
});

test('a handover waits on the receiver, and carries both names once answered', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $oga = User::find($vendor->user_id);

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute($chioma, $oga, $store, 50000);

    expect($submission->status)->toBe(CashSubmission::STATUS_PENDING);

    $confirmed = app(ResolveCashSubmissionAction::class)->confirm($submission, $oga);

    expect($confirmed->status)->toBe(CashSubmission::STATUS_CONFIRMED)
        ->and($confirmed->confirmed_at)->not->toBeNull();
});

test('only the person it was handed to can answer for it', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $stranger = User::factory()->create();
    $oga = User::find($vendor->user_id);

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute($chioma, $oga, $store, 50000);

    expect(fn () => app(ResolveCashSubmissionAction::class)->confirm($submission, $stranger))
        ->toThrow(RuntimeException::class, 'Only the person it was handed to');

    // Not even the person who handed it over may sign it off.
    expect(fn () => app(ResolveCashSubmissionAction::class)->confirm($submission, $chioma))
        ->toThrow(RuntimeException::class, 'Only the person it was handed to');
});

test('a disputed handover puts the money back on the person who says they gave it', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $oga = User::find($vendor->user_id);

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute($chioma, $oga, $store, 50000);

    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(0.0);

    app(ResolveCashSubmissionAction::class)->dispute($submission, $oga, 'Only 30,000 reached me', 30000);

    // Denied receipt must not clear the submitter: otherwise saying "it never
    // arrived" would be the easiest way to make money disappear.
    expect(CashDrawer::expectedFrom($vendor->id, $store->id, $chioma->id))->toBe(50000.0)
        ->and($submission->fresh()->amount)->toEqual(50000.0)
        ->and($submission->fresh()->disputed_amount)->toEqual(30000.0);
});

test('a handover cannot be answered twice', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create();
    $oga = User::find($vendor->user_id);

    cashSale($vendor, $store, $chioma->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute($chioma, $oga, $store, 50000);

    app(ResolveCashSubmissionAction::class)->confirm($submission, $oga);

    expect(fn () => app(ResolveCashSubmissionAction::class)->dispute($submission->fresh(), $oga, 'changed my mind'))
        ->toThrow(RuntimeException::class, 'already been answered');
});

test('the owner can see who is holding cash at a branch', function () {
    $vendor = cashVendor();
    $store = $vendor->defaultStore;
    $chioma = User::factory()->create(['name' => 'Chioma']);
    $ibrahim = User::factory()->create(['name' => 'Ibrahim']);

    cashSale($vendor, $store, $chioma->id, ['total' => 40000]);
    cashSale($vendor, $store, $ibrahim->id, ['total' => 90000]);

    $holdings = CashDrawer::holdingsAt($vendor->id, $store->id);

    expect($holdings)->toHaveCount(2)
        ->and($holdings[0]['name'])->toBe('Ibrahim')
        ->and($holdings[0]['expected'])->toBe(90000.0)
        ->and($holdings[1]['expected'])->toBe(40000.0);
});
