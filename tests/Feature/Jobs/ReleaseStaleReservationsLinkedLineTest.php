<?php

use App\Actions\Inventory\ReserveStockAction;
use App\Jobs\ReleaseStaleReservationsJob;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../VendorLink/Helpers.php';

uses(RefreshDatabase::class);

// A resold (VendorLink) line never held a reservation, so the stale sweep must
// release only the stock-tracked lines and write nothing for the linked one.

function staleLinkedOrder(): array
{
    $reseller = linkVendor('Stale Reseller');
    $own      = linkProduct($reseller, price: 5000, stock: 10);
    $supplier = linkVendor('Stale Supplier');
    $source   = linkProduct($supplier, price: 4000, stock: 6);
    $link     = makeLink($reseller, $supplier);

    $listing = Product::create([
        'vendor_id' => $reseller->id, 'store_id' => $reseller->defaultStore->id,
        'category_id' => $source->category_id, 'source_product_id' => $source->id,
        'supplier_link_id' => $link->id, 'name' => 'Stale Resold', 'price' => 5600,
        'stock_quantity' => 0, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-STALE-'.uniqid(), 'customer_name' => 'Quiet Buyer',
        'customer_email' => 'q@example.com', 'customer_phone' => '08040000000',
        'shipping_address' => 'Uyo', 'total_amount' => 15600,
        'status' => 'confirmed', 'payment_method' => 'pay_on_delivery',
    ]);

    $ownLine = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $own->id,
        'vendor_id' => $reseller->id, 'quantity' => 2, 'unit_price' => 5000,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $listing->id,
        'vendor_id' => $reseller->id, 'quantity' => 1, 'unit_price' => 5600,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $own->id, quantity: 2, reference: $order->reference, orderItemId: $ownLine->id,
    );

    $order->forceFill(['reserved_at' => now()->subHours(30)])->saveQuietly();

    return compact('order', 'own', 'listing');
}

test('the stale sweep releases stock-tracked lines and ignores linked lines', function () {
    $c = staleLinkedOrder();
    // Only a hold nobody has paid or committed to is released (a confirmed
    // pay-on-delivery order alerts the admins instead — OrderStatusServiceTest).
    $c['order']->forceFill(['status' => 'pending'])->saveQuietly();

    (new ReleaseStaleReservationsJob)->handle();

    expect($c['order']->fresh()->reservation_released_at)->not->toBeNull()
        ->and(ProductStoreStock::where('product_id', $c['own']->id)->value('reserved'))->toBe(0)
        ->and(InventoryLedger::where('product_id', $c['own']->id)->where('transaction_type', 'reservation_released')->count())->toBe(1)
        ->and(InventoryLedger::where('product_id', $c['listing']->id)->count())->toBe(0);
});

test('orders:report-failed-stock lists stuck orders, flags linked lines and changes nothing', function () {
    $c = staleLinkedOrder();
    $c['order']->forceFill(['status' => 'paid_but_failed_stock'])->saveQuietly();

    expect(Artisan::call('orders:report-failed-stock'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain($c['order']->reference)
        ->toContain('YES (mixed)')
        ->toContain('1 contain linked lines');

    expect($c['order']->fresh()->status)->toBe('paid_but_failed_stock');
});
