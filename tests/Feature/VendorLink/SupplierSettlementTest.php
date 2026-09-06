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

describe('booking what the supplier is owed', function () {
    test('delivery books the debt, frozen at his price that day', function () {
        ['order' => $order, 'link' => $link] = orderForListing(quantity: 2, supplierPrice: 10000);

        $order->update(['status' => 'delivered']);

        $entry = SupplierPayableEntry::charges()->firstOrFail();

        expect((float) $entry->unit_cost)->toBe(10000.0)
            ->and((int) $entry->quantity)->toBe(2)
            ->and((float) $entry->amount)->toBe(20000.0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0);
    });

    test('an undelivered order owes him nothing', function () {
        ['order' => $order, 'link' => $link] = orderForListing();

        $order->update(['status' => 'confirmed']);

        expect(SupplierPayableEntry::count())->toBe(0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0);
    });

    test('a cancelled order owes him nothing — the race costs nobody', function () {
        ['order' => $order, 'link' => $link] = orderForListing();

        // He sold the last unit at his own counter first. Pay on delivery, so
        // nothing was paid and nothing is lost.
        $order->update(['status' => 'cancelled']);

        expect(SupplierPayableEntry::count())->toBe(0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0);
    });

    test('delivery firing twice books the debt once', function () {
        ['order' => $order, 'link' => $link] = orderForListing(quantity: 2);

        $order->update(['status' => 'delivered']);
        // A status flipped back and forth, a retried job, two tabs.
        $order->update(['status' => 'shipped']);
        $order->update(['status' => 'delivered']);

        expect(SupplierPayableEntry::charges()->count())->toBe(1)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0);
    });

    test('his later price rise does not restate a debt already booked', function () {
        ['order' => $order, 'source' => $source, 'link' => $link] = orderForListing(quantity: 1, supplierPrice: 10000);

        $order->update(['status' => 'delivered']);
        $source->update(['price' => 18000]);
        $order->update(['status' => 'delivered']);

        expect(app(SupplierPayable::class)->balance($link))->toBe(10000.0);
    });

    test('a prepaid order books nothing, because its revenue landed earlier', function () {
        ['order' => $order, 'link' => $link, 'item' => $item] = orderForListing(quantity: 2);

        // Prepaid recognises revenue at 'paid' and delivers later, so booking
        // the cost at delivery would put the earning in one period and the cost
        // of earning it in another. Pay-on-delivery is the arrangement this
        // feature serves; a prepaid resale needs its timing decided on purpose
        // before it books anything.
        $order->update(['payment_method' => 'paystack']);
        $order->update(['status' => 'delivered']);

        expect(SupplierPayableEntry::count())->toBe(0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(0.0)
            ->and($item->fresh()->unit_cost)->toBeNull();
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

        $order->update(['status' => 'delivered']);

        expect(SupplierPayableEntry::count())->toBe(0);
    });
});

describe('cost of goods sold', function () {
    test('the frozen supplier price lands on the order line as cost', function () {
        ['order' => $order, 'item' => $item] = orderForListing(quantity: 2, supplierPrice: 10000);

        expect($item->unit_cost)->toBeNull();

        $order->update(['status' => 'delivered']);

        // The column the rest of the platform already reads for COGS, so
        // reporting needs no knowledge of VendorLink.
        expect((float) $item->fresh()->unit_cost)->toBe(10000.0);
    });

    test('profit is retail less what the supplier charged', function () {
        ['order' => $order, 'reseller' => $reseller] = orderForListing(quantity: 1, supplierPrice: 10000, markup: 40);

        $order->update([
            'status'                => 'delivered',
            'revenue_recognized_at' => now(),
        ]);

        $summary = app(SalesReportService::class)->summary(
            $reseller->id,
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
        );

        // Sold at 14,990, owed 10,000 — the markup is the business.
        expect($summary['revenue'])->toBe(14990.0)
            ->and($summary['cost'])->toBe(10000.0)
            ->and($summary['profit'])->toBe(4990.0);
    });
});

describe('settling up', function () {
    test('payments reduce the balance and never touch the supplier account', function () {
        ['order' => $order, 'link' => $link, 'reseller' => $reseller, 'source' => $source] = orderForListing(quantity: 3);

        $order->update(['status' => 'delivered']);

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

        $order->update(['status' => 'delivered']);
        app(SupplierPayable::class)->pay($link, 10000);

        expect(app(SupplierPayable::class)->balance($link))->toBe(0.0)
            ->and(app(SupplierPayable::class)->outstandingByLink($link->reseller_vendor_id))->toBeEmpty();
    });
});

describe('the settlement screen', function () {
    test('shows what each supplier is owed, summed from the ledger', function () {
        ['order' => $order, 'reseller' => $reseller] = orderForListing(quantity: 2, supplierPrice: 10000);

        $order->update(['status' => 'delivered']);

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

        $order->update(['status' => 'delivered']);
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
