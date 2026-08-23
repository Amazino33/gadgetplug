<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function purgeVendor(string $name): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => $name]);
}

function purgeProduct(Vendor $vendor, ?Store $store, string $name): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store?->id,
        'category_id'    => Category::create(['name' => 'C' . uniqid()])->id,
        'name'           => $name,
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);
}

function purgeSale(Vendor $vendor, ?Store $store, string $ref, string $when, ?Product $product = null): PosSale
{
    // pos_sale_items.product_id is NOT NULL, so a sale always needs a real line.
    $product ??= purgeProduct($vendor, $store, 'Line for ' . $ref);

    $sale = PosSale::create([
        'reference'       => $ref,
        'vendor_id'       => $vendor->id,
        'store_id'        => $store?->id,
        'cashier_id'      => User::factory()->create()->id,
        'subtotal'        => 1000,
        'vat_amount'      => 0,
        'total'           => 1000,
        'payment_method'  => 'cash',
        'amount_tendered' => 1000,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => Carbon::parse($when),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $sale;
}

test('a dry run reports the damage and deletes nothing', function () {
    $vendor = purgeVendor('Dry Run Store');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    purgeProduct($vendor, $main, 'Test Widget');
    purgeSale($vendor, $main, 'POS-DRY1', '2026-08-10 10:00');

    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14")
        ->assertSuccessful();

    expect(Product::where('name', 'Test Widget')->count())->toBe(1)
        ->and(PosSale::count())->toBe(1);
});

test('with --force it removes this store products and dated POS sales', function () {
    $vendor = purgeVendor('Purge Store');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    purgeProduct($vendor, $main, 'Test Widget');
    purgeSale($vendor, $main, 'POS-OLD', '2026-08-10 10:00');
    $kept = purgeSale($vendor, $main, 'POS-NEW', '2026-08-22 12:44');

    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14 --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    // 'Test Widget' has no history, so it goes. The product backing the kept
    // 22 Aug sale is deliberately spared — deleting it would strip the line
    // items off a sale we were told to keep.
    expect(Product::where('name', 'Test Widget')->count())->toBe(0)
        ->and(Product::where('name', 'Line for POS-NEW')->count())->toBe(1)
        ->and(Product::where('name', 'Line for POS-OLD')->count())->toBe(0)
        ->and(PosSale::pluck('reference')->all())->toBe(['POS-NEW'])
        ->and(PosSale::find($kept->id))->not->toBeNull()
        // The kept sale still has its line intact.
        ->and(DB::table('pos_sale_items')->where('pos_sale_id', $kept->id)->count())->toBe(1);
});

test('another store under the same vendor is untouched', function () {
    $vendor = purgeVendor('Two Branch Store');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $other  = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch Two']);

    purgeProduct($vendor, $main, 'Main Widget');
    $safeProduct = purgeProduct($vendor, $other, 'Branch Widget');
    purgeSale($vendor, $main, 'POS-MAIN', '2026-08-10 10:00');
    $safeSale = purgeSale($vendor, $other, 'POS-BRANCH', '2026-08-10 10:00');

    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14 --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    expect(Product::find($safeProduct->id))->not->toBeNull()
        ->and(PosSale::find($safeSale->id))->not->toBeNull();
});

test('another vendor is untouched entirely', function () {
    $mine   = purgeVendor('Mine');
    $theirs = purgeVendor('Theirs');
    $main   = Store::where('vendor_id', $mine->id)->where('is_default', true)->first();
    $theirMain = Store::where('vendor_id', $theirs->id)->where('is_default', true)->first();

    purgeProduct($mine, $main, 'My Widget');
    $safe = purgeProduct($theirs, $theirMain, 'Their Widget');
    purgeSale($theirs, $theirMain, 'POS-THEIRS', '2026-08-10 10:00');

    $this->artisan("store:purge {$mine->id} {$main->id} --pos-before=2026-08-14 --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    expect(Product::find($safe->id))->not->toBeNull()
        ->and(PosSale::where('vendor_id', $theirs->id)->count())->toBe(1);
});

test('a shared online order keeps another vendor lines and survives', function () {
    $mine   = purgeVendor('Shared Mine');
    $theirs = purgeVendor('Shared Theirs');
    $main   = Store::where('vendor_id', $mine->id)->where('is_default', true)->first();

    $myProduct    = purgeProduct($mine, $main, 'My Line');
    $theirProduct = purgeProduct($theirs, null, 'Their Line');

    $order = Order::create([
        'reference' => 'GP-SHARED', 'customer_name' => 'A', 'customer_email' => 'a@b.c',
        'customer_phone' => '080', 'shipping_address' => '1 Test Rd', 'payment_method' => 'paystack', 'total_amount' => 2000, 'status' => 'pending',
    ]);

    $myItem = OrderItem::create(['order_id' => $order->id, 'product_id' => $myProduct->id,
        'vendor_id' => $mine->id, 'quantity' => 1, 'unit_price' => 1000]);
    $theirItem = OrderItem::create(['order_id' => $order->id, 'product_id' => $theirProduct->id,
        'vendor_id' => $theirs->id, 'quantity' => 1, 'unit_price' => 1000]);

    $this->artisan("store:purge {$mine->id} {$main->id} --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    // My line goes, theirs stays, and the order itself must survive because it
    // still has content.
    expect(OrderItem::find($myItem->id))->toBeNull()
        ->and(OrderItem::find($theirItem->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull();
});

test('an order left with nothing in it is removed', function () {
    $vendor = purgeVendor('Sole Vendor');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $product = purgeProduct($vendor, $main, 'Only Line');

    $order = Order::create([
        'reference' => 'GP-SOLO', 'customer_name' => 'A', 'customer_email' => 'a@b.c',
        'customer_phone' => '080', 'shipping_address' => '1 Test Rd', 'payment_method' => 'paystack', 'total_amount' => 1000, 'status' => 'pending',
    ]);

    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $vendor->id, 'quantity' => 1, 'unit_price' => 1000]);

    $this->artisan("store:purge {$vendor->id} {$main->id} --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    expect(Order::find($order->id))->toBeNull();
});

test('financial ledger entries for the deleted sales go with them', function () {
    $vendor = purgeVendor('Ledger Store');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $sale   = purgeSale($vendor, $main, 'POS-LEDGER', '2026-08-10 10:00');

    $accountId = DB::table('financial_accounts')->insertGetId([
        'vendor_id' => $vendor->id, 'name' => 'Cash', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('financial_ledger_entries')->insert([
        'financial_account_id' => $accountId, 'direction' => 'in', 'amount' => 1000,
        // Written the way FinancialLedger actually writes it. Hardcoding a
        // guessed string here is what let the command silently match nothing.
        'source_type' => $sale->getMorphClass(), 'source_id' => $sale->id,
        'description' => 'Test', 'occurred_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14 --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    expect(DB::table('financial_ledger_entries')->where('source_id', $sale->id)->count())->toBe(0);
});

test('--keep-products removes trading data but leaves the catalogue', function () {
    $vendor = purgeVendor('Keep Catalogue');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    $product = purgeProduct($vendor, $main, 'Kept Widget');
    purgeSale($vendor, $main, 'POS-KEEP', '2026-08-10 10:00');

    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14 --keep-products --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'yes')
        ->assertSuccessful();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(PosSale::count())->toBe(0);
});

test('answering no to the confirmation deletes nothing', function () {
    $vendor = purgeVendor('Chickened Out');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    purgeProduct($vendor, $main, 'Safe Widget');

    $this->artisan("store:purge {$vendor->id} {$main->id} --force")
        ->expectsConfirmation('This permanently deletes the rows listed above. Continue?', 'no')
        ->assertSuccessful();

    expect(Product::where('name', 'Safe Widget')->count())->toBe(1);
});

test('an unknown store is refused rather than guessed at', function () {
    $vendor = purgeVendor('Unknown Store');

    $this->artisan("store:purge {$vendor->id} not-a-real-store --force")->assertFailed();
});

test('the ledger lookup uses the same source_type the app writes, not a guess', function () {
    $vendor = purgeVendor('Morph Store');
    $main   = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $sale   = purgeSale($vendor, $main, 'POS-MORPH', '2026-08-10 10:00');

    $accountId = DB::table('financial_accounts')->insertGetId([
        'vendor_id' => $vendor->id, 'name' => 'Cash', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('financial_ledger_entries')->insert([
        'financial_account_id' => $accountId, 'direction' => 'in', 'amount' => 5000,
        'source_type' => $sale->getMorphClass(), 'source_id' => $sale->id,
        'description' => 'Revenue', 'occurred_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The dry run must SEE the entry — reporting zero here is how a purge
    // deletes the sale and leaves its revenue behind.
    $this->artisan("store:purge {$vendor->id} {$main->id} --pos-before=2026-08-14")
        ->expectsOutputToContain('5,000.00')
        ->assertSuccessful();

    expect(DB::table('financial_ledger_entries')->count())->toBe(1);
});
