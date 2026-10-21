<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplierPayableEntry;
use App\Models\User;
use App\Services\Reporting\SalesReportService;
use App\Services\VendorLink\SupplierPayable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/** A resold listing, plus a pay-on-delivery order for it sitting at pending. */
function orderForListing(int $quantity = 1, float $supplierPrice = 10000, float $markup = 40): array
{
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: $markup);
    $source = linkProduct($supplier, price: $supplierPrice, stock: 20);

    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);
    $listing = Product::linked()->firstOrFail();

    $order = Order::create([
        'user_id'          => User::factory()->create()->id,
        'reference'        => 'ORD-'.Str::random(8),
        'customer_name'    => 'A Customer',
        'customer_email'   => 'customer@example.test',
        'customer_phone'   => '08000000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => (float) $listing->price * $quantity,
        'status'           => 'pending',
        'payment_method'   => 'pay_on_delivery',
    ]);

    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $listing->id,
        'vendor_id'  => $reseller->id,
        'quantity'   => $quantity,
        'unit_price' => $listing->price,
        'unit_cost'  => null,
    ]);

    return compact('order', 'item', 'listing', 'source', 'link', 'reseller');
}

/**
 * GadgetPlug records the pay-on-delivery money as received — the moment the
 * order's revenue, and with it what the supplier is owed, books. The order is
 * put at "handed to rider" directly: getting it there is not under test here.
 */
function receiveSupplierOrderPayment(Order $order): ?string
{
    $admin = User::factory()->create();
    Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    $order->refresh();

    if (! in_array($order->status, ['shipped', 'delivered'], true)) {
        $order->updateQuietly(['status' => 'shipped']);
    }

    return app(App\Services\Orders\OrderStatusService::class)->recordPaymentReceived($order->fresh(), $admin, 'cash');
}

describe('booking what the supplier is owed', function () {
    test('payment received books the debt, frozen at his price that day', function () {
        ['order' => $order, 'link' => $link] = orderForListing(quantity: 2, supplierPrice: 10000);

        receiveSupplierOrderPayment($order);

        $entry = SupplierPayableEntry::charges()->firstOrFail();

        expect((float) $entry->unit_cost)->toBe(10000.0)
            ->and((int) $entry->quantity)->toBe(2)
            ->and((float) $entry->amount)->toBe(20000.0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0);
    });

    test('an order whose money has not come in owes him nothing — even delivered', function () {
        ['order' => $order, 'link' => $link] = orderForListing();

        // Delivered is a logistics fact; nothing is booked until the money is.
        $order->updateQuietly(['status' => 'delivered']);

        expect(SupplierPayableEntry::count())->toBe(0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0);
    });

    test('a cancelled order owes him nothing — the race costs nobody', function () {
        ['order' => $order, 'link' => $link] = orderForListing();

        // He sold the last unit at his own counter first. Pay on delivery, so
        // nothing was paid and nothing is lost.
        $order->updateQuietly(['status' => 'cancelled']);

        expect(SupplierPayableEntry::count())->toBe(0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0);
    });

    test('recording the payment twice books the debt once', function () {
        ['order' => $order, 'link' => $link] = orderForListing(quantity: 2);

        receiveSupplierOrderPayment($order);
        // Two tabs, a double click.
        receiveSupplierOrderPayment($order);
        app(App\Actions\VendorLink\BookSupplierPayableAction::class)->execute($order->fresh());

        expect(SupplierPayableEntry::charges()->count())->toBe(1)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0);
    });

    test('his later price rise does not restate a debt already booked', function () {
        ['order' => $order, 'source' => $source, 'link' => $link] = orderForListing(quantity: 1, supplierPrice: 10000);

        receiveSupplierOrderPayment($order);
        $source->update(['price' => 18000]);
        app(App\Actions\VendorLink\BookSupplierPayableAction::class)->execute($order->fresh());

        expect(app(SupplierPayable::class)->balance($link))->toBe(10000.0);
    });

    test('a prepaid order books the debt when it is paid, with its revenue', function () {
        ['order' => $order, 'link' => $link, 'item' => $item] = orderForListing(quantity: 2, supplierPrice: 10000);

        // Revenue and the cost of earning it land together on both channels:
        // Paystack at paid, pay-on-delivery at payment received.
        $order->update(['payment_method' => 'paystack']);
        app(App\Services\Orders\OrderStatusService::class)->markPaid($order->fresh());

        expect(SupplierPayableEntry::charges()->count())->toBe(1)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0)
            ->and((float) $item->fresh()->unit_cost)->toBe(10000.0);
    });

    test('an ordinary product owes no supplier anything', function () {
        $vendor = linkVendor('Ordinary');
        $product = linkProduct($vendor, price: 5000, stock: 10);

        $order = Order::create([
            'user_id'          => User::factory()->create()->id,
            'reference'        => 'ORD-'.Str::random(8),
            'customer_name'    => 'A Customer',
            'customer_email'   => 'c@example.test',
            'customer_phone'   => '08000000000',
            'shipping_address' => '1 Test Street',
            'total_amount'     => 5000,
            'status'           => 'pending',
            'payment_method'   => 'pay_on_delivery',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $vendor->id,
            'quantity' => 1, 'unit_price' => 5000, 'unit_cost' => 3000,
        ]);

        receiveSupplierOrderPayment($order);

        expect(SupplierPayableEntry::count())->toBe(0);
    });
});

describe('cost of goods sold', function () {
    test('the frozen supplier price lands on the order line as cost', function () {
        ['order' => $order, 'item' => $item] = orderForListing(quantity: 2, supplierPrice: 10000);

        expect($item->unit_cost)->toBeNull();

        receiveSupplierOrderPayment($order);

        // The column the rest of the platform already reads for COGS, so
        // reporting needs no knowledge of VendorLink.
        expect((float) $item->fresh()->unit_cost)->toBe(10000.0);
    });

    test('profit is retail less what the supplier charged', function () {
        ['order' => $order, 'reseller' => $reseller] = orderForListing(quantity: 1, supplierPrice: 10000, markup: 40);

        // Delivered and paid for: the Sales Report counts paid/delivered orders.
        $order->updateQuietly(['status' => 'delivered']);
        receiveSupplierOrderPayment($order);

        $summary = app(SalesReportService::class)->summary(
            $reseller->id,
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
        );

        // Sold at 14,000, owed 10,000 — the markup is the business.
        expect($summary['revenue'])->toBe(14000.0)
            ->and($summary['cost'])->toBe(10000.0)
            ->and($summary['profit'])->toBe(4000.0);
    });
});

describe('settling up', function () {
    test('payments reduce the balance and never touch the supplier account', function () {
        ['order' => $order, 'link' => $link, 'reseller' => $reseller, 'source' => $source] = orderForListing(quantity: 3);

        receiveSupplierOrderPayment($order);

        $payable = app(SupplierPayable::class);
        $payable->pay($link, 12000, User::find($reseller->user_id), 'Part payment');

        $summary = $payable->summary($link);

        expect($summary['charged'])->toBe(30000.0)
            ->and($summary['paid'])->toBe(12000.0)
            ->and($summary['balance'])->toBe(18000.0)
            // His shop is untouched by any of it.
            ->and((int) $source->fresh()->stock_quantity)->toBe(20)
            ->and((float) $source->fresh()->price)->toBe(10000.0);
    });

    test('paying it off leaves nothing owed', function () {
        ['order' => $order, 'link' => $link] = orderForListing(quantity: 1);

        receiveSupplierOrderPayment($order);
        app(SupplierPayable::class)->pay($link, 10000);

        expect(app(SupplierPayable::class)->balance($link))->toBe(0.0)
            ->and(app(SupplierPayable::class)->outstandingByLink($link->reseller_vendor_id))->toBeEmpty();
    });
});

describe('the settlement screen', function () {
    test('shows what each supplier is owed, summed from the ledger', function () {
        ['order' => $order, 'reseller' => $reseller] = orderForListing(quantity: 2, supplierPrice: 10000);

        receiveSupplierOrderPayment($order);

        settlementPanel($reseller, User::find($reseller->user_id));

        $page = Livewire\Livewire::test(App\Filament\Vendor\Pages\SupplierPayablePage::class)->assertOk();
        $rows = $page->instance()->balances();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['charged'])->toBe(20000.0)
            ->and($rows[0]['balance'])->toBe(20000.0)
            ->and($page->instance()->totalOwed())->toBe(20000.0);
    });

    test('a payment recorded on the screen moves the derived balance', function () {
        ['order' => $order, 'reseller' => $reseller, 'link' => $link] = orderForListing(quantity: 2);

        receiveSupplierOrderPayment($order);
        settlementPanel($reseller, User::find($reseller->user_id));

        Livewire\Livewire::test(App\Filament\Vendor\Pages\SupplierPayablePage::class)
            ->callAction('pay', data: ['amount' => 7500, 'note' => 'Cash'], arguments: ['link' => $link->id]);

        expect(app(SupplierPayable::class)->balance($link->fresh()))->toBe(12500.0)
            ->and(SupplierPayableEntry::payments()->count())->toBe(1);
    });

    test('another vendor supplier cannot be paid from this screen', function () {
        ['reseller' => $reseller] = orderForListing();

        // Somebody else's arrangement entirely.
        $outsider = linkVendor('Outsider');
        $othersLink = makeLink($outsider, linkVendor('Their Wholesaler'));

        settlementPanel($reseller, User::find($reseller->user_id));

        Livewire\Livewire::test(App\Filament\Vendor\Pages\SupplierPayablePage::class)
            ->callAction('pay', data: ['amount' => 5000], arguments: ['link' => $othersLink->id]);

        // The link is re-checked against this tenant, so a posted id buys
        // nothing however it arrived.
        expect(SupplierPayableEntry::payments()->count())->toBe(0);
    });

    test('only the owner may settle up', function () {
        ['reseller' => $reseller] = orderForListing();

        $member = User::factory()->create();
        $reseller->users()->syncWithoutDetaching([$member->id]);

        settlementPanel($reseller, User::find($reseller->user_id));
        expect(App\Filament\Vendor\Pages\SupplierPayablePage::canAccess())->toBeTrue();

        settlementPanel($reseller, $member);
        expect(App\Filament\Vendor\Pages\SupplierPayablePage::canAccess())->toBeFalse();
    });
});

function settlementPanel(App\Models\Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::bootCurrentPanel();
    Filament\Facades\Filament::setTenant($vendor);
}

describe('cancelling after the debt was booked', function () {
    test('cancelling a paid order reverses its supplier charge with an append-only entry', function () {
        ['order' => $order, 'link' => $link, 'item' => $item] = orderForListing(quantity: 2, supplierPrice: 10000);
        $order->update(['payment_method' => 'paystack']);
        $service = app(App\Services\Orders\OrderStatusService::class);
        $service->markPaid($order->fresh());

        expect(app(SupplierPayable::class)->balance($link))->toBe(20000.0);

        $admin = User::factory()->create();
        Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
        setPermissionsTeamId(null);
        $admin->assignRole('super_admin');

        $service->cancel($order->fresh(), $admin, 'Customer refunded');

        $charge   = SupplierPayableEntry::charges()->sole();
        $reversal = SupplierPayableEntry::reversals()->sole();

        expect(app(SupplierPayable::class)->balance($link))->toBe(0.0)
            // The charge is history: still there, unchanged.
            ->and((float) $charge->amount)->toBe(20000.0)
            ->and((float) $reversal->amount)->toBe(20000.0)
            ->and($reversal->order_item_id)->toBe($item->id)
            ->and($reversal->user_id)->toBe($admin->id)
            ->and(app(SupplierPayable::class)->summary($link))->toBe(['charged' => 0.0, 'reversed' => 20000.0, 'paid' => 0.0, 'balance' => 0.0]);
    });

    test('a line is reversed once, however often the reversal is asked for', function () {
        ['order' => $order, 'link' => $link, 'item' => $item] = orderForListing(quantity: 1, supplierPrice: 10000);
        receiveSupplierOrderPayment($order);

        app(SupplierPayable::class)->reverseCharge($item->fresh());
        app(SupplierPayable::class)->reverseCharge($item->fresh());

        expect(SupplierPayableEntry::reversals()->count())->toBe(1)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0);
    });

    test('a line that was never charged has nothing to reverse', function () {
        ['item' => $item] = orderForListing();

        expect(app(SupplierPayable::class)->reverseCharge($item))->toBeNull()
            ->and(SupplierPayableEntry::count())->toBe(0);
    });

    test('money already paid to him stays paid — the reversal leaves a credit, not a rewrite', function () {
        ['order' => $order, 'link' => $link, 'item' => $item] = orderForListing(quantity: 1, supplierPrice: 10000);
        receiveSupplierOrderPayment($order);
        app(SupplierPayable::class)->pay($link, 10000);

        app(SupplierPayable::class)->reverseCharge($item->fresh());

        expect(app(SupplierPayable::class)->balance($link))->toBe(-10000.0)
            ->and(SupplierPayableEntry::payments()->count())->toBe(1);
    });
});
