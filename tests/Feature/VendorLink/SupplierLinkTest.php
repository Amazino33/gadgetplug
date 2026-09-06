<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplierLink;
use App\Models\SupplierPayableEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\SupplierLinkPolicy;
use App\Services\VendorLink\Rounding\EndsIn990;
use App\Services\VendorLink\Rounding\RoundingRules;
use App\Services\VendorLink\SupplierPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

describe('the link itself', function () {
    test('a link names a reseller and a supplier, and defaults to active', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);

        $link = makeLink($reseller, $supplier);

        expect($link->reseller->id)->toBe($reseller->id)
            ->and($link->supplier->id)->toBe($supplier->id)
            ->and($link->is_active)->toBeTrue()
            ->and($link->rounding_rule)->toBe('ends_990')
            ->and($link->isUsable())->toBeTrue();
    });

    test('the same pair cannot be linked twice', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler');

        makeLink($reseller, $supplier);

        // Two markups claiming the same catalogue is not a state that can mean
        // anything.
        expect(fn () => makeLink($reseller, $supplier))->toThrow(Exception::class);
    });

    test('a reseller can have several suppliers', function () {
        $reseller = linkVendor('Reseller');

        makeLink($reseller, linkVendor('Wholesaler A'));
        makeLink($reseller, linkVendor('Wholesaler B'));

        expect(SupplierLink::forReseller($reseller->id)->count())->toBe(2);
    });

    test('deactivating stops the link being usable without removing it', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));

        $link->update(['is_active' => false]);

        expect($link->fresh()->isUsable())->toBeFalse()
            ->and(SupplierLink::forReseller($link->reseller_vendor_id)->active()->count())->toBe(0)
            // Still there: listings and debts point at it.
            ->and(SupplierLink::find($link->id))->not->toBeNull();
    });
});

describe('marking a listing as resold', function () {
    test('an ordinary product is not linked, and every existing product stays that way', function () {
        $product = linkProduct(linkVendor('Ordinary'));

        expect($product->isLinked())->toBeFalse()
            ->and($product->source_product_id)->toBeNull()
            ->and(Product::ownStock()->count())->toBe(1)
            ->and(Product::linked()->count())->toBe(0);
    });

    test('a linked listing points at its source and its link', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier);

        $listing = linkProduct($reseller);
        $listing->update(['source_product_id' => $source->id, 'supplier_link_id' => $link->id]);

        expect($listing->fresh()->isLinked())->toBeTrue()
            ->and($listing->fresh()->sourceProduct->id)->toBe($source->id)
            ->and($listing->fresh()->supplierLink->id)->toBe($link->id)
            ->and($source->fresh()->resoldListings)->toHaveCount(1)
            ->and($link->fresh()->listings)->toHaveCount(1);
    });

    test('deleting the supplier product leaves the listing standing, unresolved', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler');
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier);

        $listing = linkProduct($reseller);
        $listing->update(['source_product_id' => $source->id, 'supplier_link_id' => $link->id]);

        $source->delete();

        // The listing must not vanish — order lines point at it. It stops
        // resolving instead, which the reseller can see and deal with.
        expect(Product::find($listing->id))->not->toBeNull()
            ->and($listing->fresh()->source_product_id)->toBeNull();
    });
});

describe('the rounding rule', function () {
    test('rounds up to end in 990', function () {
        $rule = new EndsIn990();

        expect($rule->apply(14000))->toBe(14990.0)
            ->and($rule->apply(14980))->toBe(14990.0)
            // Already on the band: left alone rather than pushed up a thousand.
            ->and($rule->apply(14990))->toBe(14990.0)
            ->and($rule->apply(15100))->toBe(15990.0);
    });

    test('never rounds down, because that would sell below the markup', function () {
        $rule = new EndsIn990();

        foreach ([1, 500, 991, 1200, 99999] as $amount) {
            expect($rule->apply($amount))->toBeGreaterThanOrEqual((float) $amount);
        }
    });

    test('the rule is swappable by name, and an unknown name falls back safely', function () {
        expect(RoundingRules::make('ends_990'))->toBeInstanceOf(EndsIn990::class)
            ->and(RoundingRules::make('none')->apply(14000.456))->toBe(14000.46)
            ->and(RoundingRules::make('nonsense'))->toBeInstanceOf(EndsIn990::class);
    });
});

describe('the payable ledger', function () {
    test('a delivered line books what is owed, frozen at the supplier price', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $item = payableOrderItem($reseller);

        $entry = app(SupplierPayable::class)->charge($link, $item, 2, 10000);

        expect($entry->entry_type)->toBe(SupplierPayableEntry::TYPE_CHARGE)
            ->and((float) $entry->unit_cost)->toBe(10000.0)
            ->and((float) $entry->amount)->toBe(20000.0)
            ->and(app(SupplierPayable::class)->balance($link))->toBe(20000.0);
    });

    test('the same delivered line never books the debt twice', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $item = payableOrderItem($reseller);

        $payable = app(SupplierPayable::class);

        $first  = $payable->charge($link, $item, 2, 10000);
        // Delivery fired again — a status flipped back and forth, a retried job.
        $second = $payable->charge($link, $item, 2, 10000);

        expect($second->id)->toBe($first->id)
            ->and(SupplierPayableEntry::charges()->count())->toBe(1)
            ->and($payable->balance($link))->toBe(20000.0);
    });

    test('a later supplier price rise does not rewrite what was already owed', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $item = payableOrderItem($reseller);

        $payable = app(SupplierPayable::class);
        $payable->charge($link, $item, 1, 10000);

        // He puts his price up. The debt for units already delivered is what he
        // charged that day.
        $payable->charge($link, $item, 1, 18000);

        expect($payable->balance($link))->toBe(10000.0);
    });

    test('payments reduce the balance and are never a balance mutation', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $payable = app(SupplierPayable::class);

        $payable->charge($link, payableOrderItem($reseller), 3, 10000);
        $payable->pay($link, 12000, User::find($reseller->user_id), 'Part payment');

        $summary = $payable->summary($link);

        expect($summary['charged'])->toBe(30000.0)
            ->and($summary['paid'])->toBe(12000.0)
            ->and($summary['balance'])->toBe(18000.0);
    });

    test('the same supplier can be paid the same amount twice in a day', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $payable = app(SupplierPayable::class);

        $payable->charge($link, payableOrderItem($reseller), 5, 10000);
        $payable->pay($link, 5000);
        $payable->pay($link, 5000);

        // Payments carry no natural key, so idempotency must not collapse them.
        expect(SupplierPayableEntry::payments()->count())->toBe(2)
            ->and($payable->balance($link))->toBe(40000.0);
    });

    test('a charge refuses nonsense rather than recording it', function () {
        $reseller = linkVendor('Reseller');
        $link = makeLink($reseller, linkVendor('Wholesaler'));
        $payable = app(SupplierPayable::class);

        expect(fn () => $payable->charge($link, payableOrderItem($reseller), 0, 10000))
            ->toThrow(RuntimeException::class, 'at least one unit');

        expect(fn () => $payable->pay($link, 0))
            ->toThrow(RuntimeException::class, 'some money');
    });

    test('the ledger row never updates, so history cannot be rewritten', function () {
        expect(SupplierPayableEntry::UPDATED_AT)->toBeNull();
    });

    test('each supplier is owed separately', function () {
        $reseller = linkVendor('Reseller');
        $a = makeLink($reseller, linkVendor('Wholesaler A'));
        $b = makeLink($reseller, linkVendor('Wholesaler B'));
        $payable = app(SupplierPayable::class);

        $payable->charge($a, payableOrderItem($reseller), 1, 10000);
        $payable->charge($b, payableOrderItem($reseller), 1, 25000);

        $owed = $payable->outstandingByLink($reseller->id);

        expect($owed)->toHaveCount(2)
            ->and($owed[0]['balance'])->toBe(25000.0)
            ->and($owed[1]['balance'])->toBe(10000.0);
    });
});

describe('who may create a link', function () {
    test('a super admin may', function () {
        $admin = User::factory()->create();
        $admin->assignRole(Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        expect(app(SupplierLinkPolicy::class)->create($admin))->toBeTrue();
    });

    test('a vendor owner may not open somebody else\'s catalogue to themselves', function () {
        $reseller = linkVendor('Reseller');
        $owner = User::find($reseller->user_id);
        $link = makeLink($reseller, linkVendor('Wholesaler'));

        $policy = app(SupplierLinkPolicy::class);

        expect($policy->create($owner))->toBeFalse()
            ->and($policy->viewAny($owner))->toBeFalse()
            ->and($policy->update($owner, $link))->toBeFalse();
    });

    test('nobody deletes a link, because listings and debts point at it', function () {
        $admin = User::factory()->create();
        $admin->assignRole(Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));

        expect(app(SupplierLinkPolicy::class)->delete($admin, $link))->toBeFalse();
    });
});

/** An order line belonging to the reseller, to hang a charge on. */
function payableOrderItem(Vendor $reseller): OrderItem
{
    $order = Order::create([
        'user_id'          => User::factory()->create()->id,
        'reference'        => 'ORD-'.Str::random(8),
        'customer_name'    => 'A Customer',
        'customer_email'   => 'customer@example.test',
        'customer_phone'   => '08000000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 0,
        'status'           => 'pending',
        'payment_method'   => 'pay_on_delivery',
    ]);

    return OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => linkProduct($reseller)->id,
        'vendor_id'  => $reseller->id,
        'quantity'   => 1,
        'unit_price' => 14990,
    ]);
}
