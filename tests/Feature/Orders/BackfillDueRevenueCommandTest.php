<?php

use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

// The one-off backfill: Paystack and wallet orders paid before the switch but
// never booked go into Due from GadgetPlug, dated the day they were paid.

/** An order as the old code left it: paid, no revenue recorded. */
function unbookedOrder(array $c, string $method, string $status, float $amount = 5000): Order
{
    $order = Order::create([
        'reference' => 'BF-'.uniqid(), 'customer_name' => 'Ada', 'customer_email' => 'ada@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => $amount, 'status' => 'pending', 'payment_method' => $method,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $c['product']->id, 'vendor_id' => $c['vendor']->id,
        'quantity' => 1, 'unit_price' => $amount,
    ]);

    // A bare write, as the old code did: logged, but books nothing now.
    $order->update(['status' => $status]);

    return $order->fresh();
}

it('is a dry run by default — prints the table and writes nothing', function () {
    $c = ossVendor();
    unbookedOrder($c, 'paystack', 'paid', 7000);

    $this->artisan('orders:backfill-due-revenue')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Paystack — paid')
        ->expectsOutputToContain('7,000.00')
        ->assertSuccessful();

    expect(FinancialLedgerEntry::count())->toBe(0)
        ->and(Order::whereNotNull('revenue_recognized_at')->count())->toBe(0);
});

it('books Paystack and wallet orders into Due with --force, dated when they were paid', function () {
    $c = ossVendor();

    $this->travelTo(now()->subDays(40)->setTime(10, 0));
    $paystack = unbookedOrder($c, 'paystack', 'pending', 7000);
    $this->travelTo(now()->addHour());
    $paystack->update(['status' => 'paid']); // the activity log records this moment
    $paidAt = now()->toImmutable();

    $this->travelTo(now()->addDays(5));
    $wallet = unbookedOrder($c, 'wallet', 'confirmed', 3000);
    WalletTransaction::create([
        'affiliate_id' => App\Models\Affiliate::findOrCreateForUser(App\Models\User::factory()->create())->id,
        'order_id' => $wallet->id, 'type' => 'debit', 'amount' => -3000, 'description' => 'Reseller purchase',
    ]);
    $debitAt = now()->toImmutable();

    $this->travelBack();

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])
        ->expectsOutputToContain('Booked 2 order(s)')
        ->assertSuccessful();

    $paystack->refresh();
    $wallet->refresh();

    expect(ossDue($c)->balance())->toBe(10000.0)
        ->and(Carbon::parse($paystack->revenue_recognized_at)->toDateTimeString())->toBe($paidAt->toDateTimeString())
        ->and(Carbon::parse($wallet->revenue_recognized_at)->toDateTimeString())->toBe($debitAt->toDateTimeString())
        ->and($wallet->payment_channel)->toBe('wallet')
        ->and(Carbon::parse(FinancialLedgerEntry::where('source_id', $paystack->id)->where('source_type', $paystack->getMorphClass())->value('occurred_at'))->toDateString())
            ->toBe($paidAt->toDateString());
});

it('books nothing twice — a second run finds nothing to do', function () {
    $c = ossVendor();
    unbookedOrder($c, 'paystack', 'delivered', 7000);

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])->assertSuccessful();
    $this->artisan('orders:backfill-due-revenue', ['--force' => true])
        ->expectsOutputToContain('Nothing to book')
        ->assertSuccessful();

    expect(ossDue($c)->balance())->toBe(7000.0)
        ->and(FinancialLedgerEntry::count())->toBe(1);
});

it('leaves pay-on-delivery, unpaid, cancelled and already-booked orders alone, and reports stuck ones', function () {
    $c = ossVendor();
    unbookedOrder($c, 'pay_on_delivery', 'delivered');
    unbookedOrder($c, 'paystack', 'pending');
    unbookedOrder($c, 'paystack', 'cancelled');
    unbookedOrder($c, 'paystack', 'paid_but_failed_stock');
    ossOrder($c, 'paystack', 'paid', 1); // booked by the service already

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])
        ->expectsOutputToContain('Nothing to book')
        ->expectsOutputToContain('paid_but_failed_stock')
        ->assertSuccessful();

    expect(ossDue($c)->balance())->toBe(5000.0);
});

it('falls back to when stock was reserved on payment, then to the order date', function () {
    $c = ossVendor();
    $order = unbookedOrder($c, 'paystack', 'paid', 7000);

    // No activity trail (e.g. imported data), but the payment reserved stock.
    Spatie\Activitylog\Models\Activity::query()->delete();
    $reservedAt = now()->subDays(3)->startOfMinute();
    $order->updateQuietly(['reserved_at' => $reservedAt]);

    $this->artisan('orders:backfill-due-revenue')
        ->expectsOutputToContain('stock reserved on payment')
        ->assertSuccessful();

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])->assertSuccessful();

    expect(Carbon::parse($order->fresh()->revenue_recognized_at)->toDateTimeString())->toBe($reservedAt->toDateTimeString());
});

// ── Supplier debt priced at the time of sale ────────────────────────────────

require_once __DIR__.'/../VendorLink/Helpers.php';

/** A paid storefront order for a resold listing, with no revenue or debt booked yet. */
function unbookedResale(float $supplierPrice, ?float $lineSnapshot = null): array
{
    $reseller = linkVendor('Backfill Reseller');
    $supplier = linkVendor('Backfill Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);
    $source = linkProduct($supplier, price: $supplierPrice, stock: 20);
    app(App\Actions\VendorLink\PublishLinkedListingAction::class)->execute($link, [$source->id]);
    $listing = App\Models\Product::linked()->firstOrFail();

    $order = Order::create([
        'reference' => 'BF-RS-'.uniqid(), 'customer_name' => 'Ada', 'customer_email' => 'ada@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => (float) $listing->price * 2, 'status' => 'pending', 'payment_method' => 'paystack',
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $listing->id, 'vendor_id' => $reseller->id,
        'quantity' => 2, 'unit_price' => $listing->price, 'unit_cost' => $lineSnapshot,
    ]);
    $order->update(['status' => 'paid']);

    return compact('order', 'item', 'source', 'link', 'listing');
}

it('books the supplier debt at the line\'s own snapshot when there is one', function () {
    $r = unbookedResale(supplierPrice: 10000, lineSnapshot: 9000);
    $r['source']->update(['price' => 12000]);

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])->assertSuccessful();

    expect((float) App\Models\SupplierPayableEntry::charges()->sole()->unit_cost)->toBe(9000.0);
});

it('books the supplier debt at his price on the sale date, from his price history', function () {
    $this->travelTo(now()->subDays(10));
    $r = unbookedResale(supplierPrice: 10000);
    $this->travelBack();

    // He has put his price up since.
    $r['source']->update(['price' => 15000]);

    $this->artisan('orders:backfill-due-revenue')
        ->expectsOutputToContain(App\Services\VendorLink\SupplierPriceAtSale::FROM_HISTORY)
        ->doesntExpectOutputToContain("would be booked at the supplier's price today")
        ->assertSuccessful();

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])->assertSuccessful();

    expect((float) App\Models\SupplierPayableEntry::charges()->sole()->unit_cost)->toBe(10000.0)
        ->and((float) $r['item']->fresh()->unit_cost)->toBe(10000.0);
});

it('falls back to today\'s price only with no record, and lists the line with both prices and the difference', function () {
    $r = unbookedResale(supplierPrice: 10000);

    // No history at all for his product, and his price has moved.
    Spatie\Activitylog\Models\Activity::query()->where('subject_id', $r['source']->id)->delete();
    $r['source']->updateQuietly(['price' => 11500]);

    Illuminate\Support\Facades\Artisan::call('orders:backfill-due-revenue');
    $output = Illuminate\Support\Facades\Artisan::output();

    // One row: the order, today's price, the listing's last recorded cost,
    // and the difference per unit and per line (2 units).
    expect($output)->toContain("would be booked at the supplier's price today")
        ->and($output)->toMatch('/'.preg_quote($r['order']->reference, '/').'[^\n]*11,500\.00[^\n]*10,000\.00[^\n]*1,500\.00[^\n]*3,000\.00/');

    $this->artisan('orders:backfill-due-revenue', ['--force' => true])->assertSuccessful();

    expect((float) App\Models\SupplierPayableEntry::charges()->sole()->unit_cost)->toBe(11500.0);
});
