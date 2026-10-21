<?php

use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Models\Cutover;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Services\Orders\InvalidOrderTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

// A pay-on-delivery order placed before the switch to Due from GadgetPlug may
// have had its cash taken by the vendor's own rider. For those orders only,
// the admin can record the payment as "collected by vendor (legacy)", which
// books it the old way: to the vendor's own cash account.

function setCutover(Carbon\CarbonInterface $at): void
{
    Cutover::updateOrCreate(['key' => Cutover::ORDER_STATUS_SERVICE], ['happened_at' => $at]);
}

function vendorCash(array $c): FinancialAccount
{
    return FinancialAccount::where('vendor_id', $c['vendor']->id)->where('type', 'cash')->orderBy('id')->firstOrFail();
}

it('records the cut-over when its migration runs', function () {
    expect(Cutover::at(Cutover::ORDER_STATUS_SERVICE))->not->toBeNull();
});

it('books a pre-cut-over order collected by the vendor to the vendor\'s own cash account, not Due', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    setCutover(now()->addMinute());

    $reason = oss()->recordPaymentReceived($order, $c['admin'], 'bank_transfer', collectedByVendor: true);
    $order->refresh();

    expect($reason)->toBeNull()
        ->and($order->isRevenueRecognized())->toBeTrue()
        // The vendor's rider took cash: always the cash channel.
        ->and($order->payment_channel)->toBe('cash')
        ->and(vendorCash($c)->balance())->toBe(10000.0)
        ->and(ossDue($c)->balance())->toBe(0.0);
});

it('refuses the legacy option on an order placed after the cut-over', function () {
    $c = ossVendor();
    setCutover(now()->subMinute());
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');

    expect(fn () => oss()->recordPaymentReceived($order, $c['admin'], 'cash', collectedByVendor: true))
        ->toThrow(InvalidOrderTransition::class)
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

it('treats a missing cut-over record as "no legacy orders"', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    Cutover::query()->delete();

    expect($order->predatesStatusCutover())->toBeFalse();
});

it('still books a pre-cut-over order to Due when GadgetPlug collected the money', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'pay_on_delivery', 'delivered');
    setCutover(now()->addMinute());

    oss()->recordPaymentReceived($order, $c['admin'], 'cash');

    expect(ossDue($c)->balance())->toBe(10000.0)
        ->and(vendorCash($c)->balance())->toBe(0.0);
});

it('offers "Collected by vendor (legacy)" on the admin page only for pre-cut-over orders', function () {
    $c = ossVendor();
    $old = ossOrder($c, 'pay_on_delivery', 'shipped', 1);
    setCutover(now()->addMinute());
    ossActAsAdmin($c['admin']);

    Livewire::test(AdminViewOrder::class, ['record' => $old->getRouteKey()])
        ->mountAction('recordPaymentReceived')
        ->assertFormFieldExists('payment_channel', 'mountedActionSchema0', fn ($field) => array_key_exists('collected_by_vendor', $field->getOptions()))
        ->setActionData(['payment_channel' => 'collected_by_vendor'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(vendorCash($c)->balance())->toBe(5000.0)
        ->and(ossDue($c)->balance())->toBe(0.0);

    setCutover(now()->subMinutes(5));
    $new = ossOrder($c, 'pay_on_delivery', 'shipped', 1);

    Livewire::test(AdminViewOrder::class, ['record' => $new->getRouteKey()])
        ->mountAction('recordPaymentReceived')
        ->assertFormFieldExists('payment_channel', 'mountedActionSchema0', fn ($field) => ! array_key_exists('collected_by_vendor', $field->getOptions()));
});

it('rejects the legacy value posted for a post-cut-over order from the admin page', function () {
    $c = ossVendor();
    setCutover(now()->subMinute());
    $order = ossOrder($c, 'pay_on_delivery', 'shipped', 1);
    ossActAsAdmin($c['admin']);

    Livewire::test(AdminViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('recordPaymentReceived', data: ['payment_channel' => 'collected_by_vendor']);

    expect($order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});
