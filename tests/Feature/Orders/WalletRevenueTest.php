<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Affiliate;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Product;
use App\Models\SupplierPayableEntry;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\ResellerCheckoutService;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use App\Services\VendorLink\SupplierPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../VendorLink/Helpers.php';

// A reseller paying from their GadgetPlug wallet is paying GadgetPlug, like a
// Paystack payment: revenue (items only) goes into the selling vendor's Due
// from GadgetPlug account at the moment of the debit, with the supplier
// payable at the same moment.

function walletBuyer(float $balance): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create(['phone' => '08040000000']));

    WalletTransaction::create([
        'affiliate_id' => $affiliate->id, 'type' => 'credit', 'amount' => $balance, 'description' => 'Test credit',
    ]);

    return $affiliate;
}

it('books each vendor order of a wallet purchase into that vendor\'s Due account at the debit', function () {
    $a = ossVendor();
    $b = ossVendor();

    $checkout = app(ResellerCheckoutService::class)->purchase(walletBuyer(100000), [
        ['product' => $a['product'], 'quantity' => 1],
        ['product' => $b['product'], 'quantity' => 2],
    ], 'Uyo — test address');

    $orders = $checkout->orders->keyBy(fn ($o) => $o->items->first()->vendor_id);
    $orderA = $orders[$a['vendor']->id]->fresh();
    $orderB = $orders[$b['vendor']->id]->fresh();

    expect($orderA->isRevenueRecognized())->toBeTrue()
        ->and($orderA->payment_channel)->toBe('wallet')
        ->and($orderA->status)->toBe('confirmed')
        ->and(ossDue($a)->balance())->toBe((float) $orderA->total_amount)
        ->and(ossDue($b)->balance())->toBe((float) $orderB->total_amount)
        // Never the vendor's own bank or cash.
        ->and(FinancialAccount::where('vendor_id', $a['vendor']->id)->whereIn('type', ['bank', 'cash'])->get()->sum(fn ($acc) => $acc->balance()))->toBe(0.0);
});

it('books the supplier payable for a resold line at the wallet debit', function () {
    $reseller = linkVendor('Wallet Reseller');
    $supplier = linkVendor('Wallet Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);
    $source = linkProduct($supplier, price: 10000, stock: 20);
    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);
    $listing = Product::linked()->firstOrFail();

    app(ResellerCheckoutService::class)->purchase(walletBuyer(100000), [
        ['product' => $listing, 'quantity' => 2],
    ], 'Uyo — test address');

    expect(SupplierPayableEntry::charges()->count())->toBe(1)
        ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0)
        ->and(FinancialAccount::gadgetplugDueFor($reseller->id)->balance())->toBeGreaterThan(0.0);
});

it('books a wallet order once — recording the wallet payment again books nothing', function () {
    $c = ossVendor();
    $order = app(ResellerCheckoutService::class)->purchase(walletBuyer(50000), [
        ['product' => $c['product'], 'quantity' => 1],
    ], 'Uyo — test address')->orders->sole();

    expect(app(OrderStatusService::class)->recordWalletPayment($order->fresh()))->toBe('already_recognized')
        ->and(FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->count())->toBe(1);
});

it('refuses to treat a non-wallet order as paid from the wallet', function () {
    $c = ossVendor();

    expect(fn () => app(OrderStatusService::class)->recordWalletPayment(ossOrder($c, 'pay_on_delivery', 'confirmed', 1)))
        ->toThrow(InvalidOrderTransition::class);
});

it('reverses a cancelled wallet order\'s revenue out of Due', function () {
    $c = ossVendor();
    $order = app(ResellerCheckoutService::class)->purchase(walletBuyer(50000), [
        ['product' => $c['product'], 'quantity' => 1],
    ], 'Uyo — test address')->orders->sole();

    app(OrderStatusService::class)->cancel($order->fresh(), $c['admin'], 'Reseller changed their mind');

    expect(ossDue($c)->balance())->toBe(0.0);
});

// ── Refund on cancel ────────────────────────────────────────────────────────

it('credits the reseller\'s wallet back when a wallet order is cancelled', function () {
    $c = ossVendor();
    $buyer = walletBuyer(50000);
    $order = app(ResellerCheckoutService::class)->purchase($buyer, [
        ['product' => $c['product'], 'quantity' => 2],
    ], 'Uyo — test address')->orders->sole();

    $wallet = app(App\Services\Affiliate\WalletService::class);
    $afterPurchase = $wallet->availableBalance($buyer->id);

    oss()->cancel($order->fresh(), $c['admin'], 'Out of stock');

    $refund = WalletTransaction::where('order_id', $order->id)->where('type', 'credit')->sole();

    expect($wallet->availableBalance($buyer->id))->toBe(50000.0)
        ->and($afterPurchase)->toBe(50000.0 - (float) $order->total_amount)
        ->and((float) $refund->amount)->toBe((float) $order->total_amount)
        ->and($refund->affiliate_id)->toBe($buyer->id)
        ->and($order->fresh()->walletRefund->id)->toBe($refund->id)
        // The debit is untouched: append-only.
        ->and(WalletTransaction::where('order_id', $order->id)->where('type', 'debit')->count())->toBe(1);
});

it('refunds only the cancelled part of a mixed wallet checkout', function () {
    $a = ossVendor();
    $b = ossVendor();
    $buyer = walletBuyer(100000);

    $orders = app(ResellerCheckoutService::class)->purchase($buyer, [
        ['product' => $a['product'], 'quantity' => 1],
        ['product' => $b['product'], 'quantity' => 2],
    ], 'Uyo — test address')->orders->keyBy(fn ($o) => $o->items->first()->vendor_id);

    $cancelled = $orders[$b['vendor']->id];
    $kept = $orders[$a['vendor']->id];

    oss()->cancel($cancelled->fresh(), $a['admin'], 'This part is out of stock');

    expect(app(App\Services\Affiliate\WalletService::class)->availableBalance($buyer->id))
        ->toBe(100000.0 - (float) $kept->total_amount)
        ->and(WalletTransaction::where('order_id', $kept->id)->where('type', 'credit')->exists())->toBeFalse()
        ->and(ossDue($a)->balance())->toBe((float) $kept->total_amount);
});

it('refunds once — a second refund attempt books nothing', function () {
    $c = ossVendor();
    $buyer = walletBuyer(50000);
    $order = app(ResellerCheckoutService::class)->purchase($buyer, [
        ['product' => $c['product'], 'quantity' => 1],
    ], 'Uyo — test address')->orders->sole();

    oss()->cancel($order->fresh(), $c['admin'], 'Out of stock');

    // Cancelling again is refused outright, and the refund itself re-checks.
    expect(fn () => oss()->cancel($order->fresh(), $c['admin'], 'Again'))->toThrow(InvalidOrderTransition::class);

    $refund = (new ReflectionMethod(OrderStatusService::class, 'refundWallet'));
    $refund->invoke(app(OrderStatusService::class), $order->fresh());

    expect(WalletTransaction::where('order_id', $order->id)->where('type', 'credit')->count())->toBe(1)
        ->and(app(App\Services\Affiliate\WalletService::class)->availableBalance($buyer->id))->toBe(50000.0);
});

it('never touches a wallet when a non-wallet order is cancelled', function () {
    $c = ossVendor();
    $order = ossOrder($c, 'paystack', 'paid', 1);

    oss()->cancel($order, $c['admin'], 'Refunded by card');

    expect(WalletTransaction::count())->toBe(0);
});
