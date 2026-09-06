<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/** A resold listing on a reseller whose shop is open online. */
function linkedListing(float $price = 10000, int $stock = 5): array
{
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);
    $source = linkProduct($supplier, price: $price, stock: $stock);

    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    return [Product::linked()->firstOrFail(), $source];
}

function placeLinkedOrder(): Livewire\Features\SupportTesting\Testable
{
    return Volt::test('checkout')
        ->set('name', 'Jane Tester')
        ->set('email', 'jane@example.com')
        ->set('phone', '08040000000')
        ->set('lga', 'Uyo')
        ->set('address', '1 Test Street, enough characters')
        ->set('paymentMethod', 'pay_on_delivery')
        ->call('processCheckout');
}

test('a pay-on-delivery order for a resold listing survives, instead of being deleted', function () {
    [$listing] = linkedListing(price: 10000, stock: 5);

    Session::put('cart', [$listing->id => ['quantity' => 1, 'max' => 5]]);

    placeLinkedOrder();

    // Reserving against a listing that holds nothing throws "insufficient
    // stock", and checkout deletes the whole order when it does — so before
    // this was skipped, every order containing a resold line vanished.
    $order = Order::first();

    expect($order)->not->toBeNull()
        ->and($order->items()->count())->toBe(1)
        ->and((float) $order->total_amount)->toBe(14990.0);
});

test('nothing is reserved anywhere for a resold line', function () {
    [$listing, $source] = linkedListing(stock: 5);

    Session::put('cart', [$listing->id => ['quantity' => 2, 'max' => 5]]);
    placeLinkedOrder();

    // Not against the listing, which holds nothing...
    expect((int) ProductStoreStock::where('product_id', $listing->id)->sum('reserved'))->toBe(0)
        // ...and above all not against the supplier, whose stock and account
        // this feature never writes to.
        ->and((int) $source->fresh()->reserved_stock)->toBe(0)
        ->and((int) ProductStoreStock::where('product_id', $source->id)->sum('reserved'))->toBe(0);
});

test('the line carries no cost yet, because the supplier is paid at delivery', function () {
    [$listing] = linkedListing(price: 10000);

    Session::put('cart', [$listing->id => ['quantity' => 1, 'max' => 5]]);
    placeLinkedOrder();

    // Null says honestly that no cost is recorded. Freezing today's figure
    // would book a cost for units that may never be delivered.
    expect(OrderItem::first()->unit_cost)->toBeNull()
        ->and((float) OrderItem::first()->unit_price)->toBe(14990.0);
});

test('an ordinary product in the same order still reserves as it always did', function () {
    [$listing] = linkedListing(stock: 5);
    $reseller = App\Models\Vendor::find($listing->vendor_id);
    $own = linkProduct($reseller, price: 5000, stock: 10);

    Session::put('cart', [
        $listing->id => ['quantity' => 1, 'max' => 5],
        $own->id     => ['quantity' => 2, 'max' => 10],
    ]);

    placeLinkedOrder();

    expect(Order::first()->items()->count())->toBe(2)
        // The real one is held; the resold one is not.
        ->and((int) ProductStoreStock::where('product_id', $own->id)->sum('reserved'))->toBe(2)
        ->and((int) ProductStoreStock::where('product_id', $listing->id)->sum('reserved'))->toBe(0);
});
