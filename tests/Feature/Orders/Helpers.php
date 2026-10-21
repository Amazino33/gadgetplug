<?php

// Shared fixtures for the order status / money tests.
//
// In a helper file because Pest loads every test file into one global function
// namespace: helpers declared in one test file only exist when that file
// happens to load in the same worker, so a sibling run alone would fail.

use App\Actions\Inventory\ReserveStockAction;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\OrderStatusService;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

function oss(): OrderStatusService
{
    return app(OrderStatusService::class);
}

/** A vendor with two branches; stock of one product at each. */
function ossVendor(): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'OSS Store '.uniqid(), 'online_sales_enabled' => true]);
    $main     = $vendor->defaultStore ?? Store::create(['vendor_id' => $vendor->id, 'name' => 'Main', 'is_default' => true]);
    $branch   = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $category = Category::create(['name' => 'OSS Category '.uniqid()]);
    $product  = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'store_id' => $main->id,
        'name' => 'OSS Widget', 'price' => 5000, 'cost_price' => 3000,
        'stock_quantity' => 10, 'status' => 'published',
    ]);

    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $main->id], ['quantity' => 5, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $branch->id], ['quantity' => 5, 'reserved' => 0]);

    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole('super_admin');

    return compact('owner', 'vendor', 'main', 'branch', 'product', 'admin');
}

/**
 * An order of $qty units reserved at $store, at 'pending' — then walked to
 * $status through the service as checkout/Paystack would.
 */
function ossOrder(array $c, string $method, string $status, int $qty = 2, ?Store $store = null): Order
{
    $order = Order::create([
        'reference' => 'OSS-'.uniqid(), 'customer_name' => 'Ada', 'customer_email' => 'ada@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => $qty * 5000, 'status' => 'pending', 'payment_method' => $method,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $c['product']->id, 'vendor_id' => $c['vendor']->id,
        'quantity' => $qty, 'unit_price' => 5000,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $c['product']->id, quantity: $qty, reference: $order->reference,
        orderItemId: $item->id, store: ($store ?? $c['main'])->id,
    );

    $order = $order->fresh();

    if ($status === 'pending') {
        return $order;
    }

    $method === 'paystack' ? oss()->markPaid($order) : oss()->confirm($order);

    if (in_array($status, ['packed', 'shipped', 'delivered'], true)) {
        oss()->pack($order, $c['owner']);
    }

    if (in_array($status, ['shipped', 'delivered'], true)) {
        oss()->handOver($order, $c['owner']);
    }

    if ($status === 'delivered') {
        oss()->markDelivered($order, $c['admin'], 'Test: customer confirmed.');
    }

    return $order->fresh();
}

function ossDue(array $c): FinancialAccount
{
    return FinancialAccount::gadgetplugDueFor($c['vendor']->id);
}

function ossActAsVendor(User $user, Vendor $vendor): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

function ossActAsAdmin(User $admin): void
{
    test()->actingAs($admin);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}
