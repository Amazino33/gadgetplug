<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ReserveStockAction;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A cashier CAN sell into stock an online order has reserved — the goods are
// physically on the shelf and a rider who never shows shouldn't leave them
// dead. AdjustStockAction's own guard only checks raw quantity, never
// reserved, so this already works. What was missing is that it happened
// silently: nobody found out an online order's hold had just been sold out
// from under it. This is the flag for that.

function oversellContext(int $stock, int $reserved): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Oversell Store '.uniqid()]);
    $store    = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main', 'is_default' => true]);
    $category = Category::create(['name' => 'Oversell Category '.uniqid()]);
    $product  = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Contested Widget', 'price' => 2000, 'store_id' => $store->id,
        'stock_quantity' => $stock, 'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'GP-'.uniqid(), 'customer_name' => 'Buyer',
        'customer_email' => 'buyer@example.com', 'customer_phone' => '08040000000',
        'shipping_address' => 'Uyo', 'total_amount' => $reserved * 2000,
        'status' => 'paid', 'payment_method' => 'paystack',
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $vendor->id, 'quantity' => $reserved, 'unit_price' => 2000,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: $reserved,
        reference: $order->reference, orderItemId: $item->id, store: $store->id,
    );

    return compact('owner', 'vendor', 'store', 'product', 'order');
}

it('flags the vendor when a POS sale cuts into stock an online order reserved', function () {
    // 5 on the shelf, 3 reserved online — a POS sale of 4 leaves 1 on the
    // shelf against 3 still reserved: the online order is now short by 2.
    $c = oversellContext(stock: 5, reserved: 3);

    app(AdjustStockAction::class)->execute(
        productId: $c['product']->id, quantityChanged: -4,
        transactionType: 'pos_sale', store: $c['store']->id,
    );

    expect($c['owner']->fresh()->notifications()->count())->toBe(1)
        ->and($c['owner']->fresh()->notifications()->first()->data['title'])->toContain('oversold')
        ->and($c['owner']->fresh()->notifications()->first()->data['body'])->toContain($c['order']->reference);
});

it('does not flag a POS sale that stays within the unreserved portion of stock', function () {
    // 5 on the shelf, 3 reserved — a sale of 1 leaves 4 against 3 reserved:
    // the online order is still fully covered.
    $c = oversellContext(stock: 5, reserved: 3);

    app(AdjustStockAction::class)->execute(
        productId: $c['product']->id, quantityChanged: -1,
        transactionType: 'pos_sale', store: $c['store']->id,
    );

    expect($c['owner']->fresh()->notifications()->count())->toBe(0);
});

it('does not flag a non-POS stock movement even if it happens to cut into reserved stock', function () {
    $c = oversellContext(stock: 5, reserved: 3);

    app(AdjustStockAction::class)->execute(
        productId: $c['product']->id, quantityChanged: -4,
        transactionType: 'audit_correction', store: $c['store']->id,
    );

    expect($c['owner']->fresh()->notifications()->count())->toBe(0);
});
