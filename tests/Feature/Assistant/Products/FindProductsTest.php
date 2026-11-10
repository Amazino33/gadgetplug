<?php

declare(strict_types=1);

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Actions\Assistant\Queries\FindProductsQuery;
use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\OutOfStockQuery;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Models\AssistantGlobalSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AiClient;
use App\Services\Assistant\AssistantScope;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
 * Phase 2: products, prices and stock, as the Products screen shows them.
 *
 * Asked live on 2026-11-10 and answered "I can't check that yet": "list all
 * the power banks we have in store" and "how much is 20000mah power bank?".
 */

/** @return array{owner: User, vendor: Vendor, main: Store, ikeja: Store} */
function productsVendor(string $name = 'Zeelink Test'): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true, 'daily_message_cap' => 0, 'daily_cost_cap_micros' => 0]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    $ikeja = Store::create(['vendor_id' => $vendor->id, 'name' => "{$name} Ikeja"]);

    foreach ([$vendor->defaultStore, $ikeja] as $store) {
        $store->forceFill(['created_at' => now()->subDays(60)])->save();
    }

    return ['owner' => $owner, 'vendor' => $vendor, 'main' => $vendor->defaultStore->fresh(), 'ikeja' => $ikeja->fresh()];
}

/** A product homed at $store with $stock there. */
function productsItem(array $ctx, Store $store, string $name, float $price, float $stock, array $extra = []): Product
{
    $product = Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $store->id,
        'category_id' => Category::firstOrCreate(['name' => 'Accessories & Cables'], ['slug' => 'accessories-cables'])->id,
        'name' => $name, 'price' => $price, 'cost_price' => round($price * 0.7, 2),
        'status' => 'published', 'stock_quantity' => $stock, 'low_stock_threshold' => 5,
        ...$extra,
    ]);

    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $store->id], ['quantity' => $stock, 'reserved' => 0]);

    return $product->fresh();
}

function productsRun(User $user, Vendor $vendor, BaseAssistantQuery $query, array $scope, array $options = []): array
{
    test()->actingAs($user);

    return AssistantScope::for($user, $vendor)->run(fn () => $query->execute($scope, now(), now(), $options));
}

function productsStaff(array $ctx, array $stores, array $grant): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$user->id]);

    setPermissionsTeamId($ctx['vendor']->id);
    $role = Role::create(['name' => 'products-test-'.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['vendor']->id]);
    $role->givePermissionTo($grant);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($stores as $store) {
        $store->users()->syncWithoutDetaching([$user->id]);
    }

    return $user->fresh();
}

function productsAll(array $ctx): array
{
    return ['store_ids' => [$ctx['main']->id, $ctx['ikeja']->id]];
}

test('"how much is 20000mah power bank?": one product, a row per branch, price and stock from code', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);
    productsItem($ctx, $ctx['ikeja'], 'Oraimo  traveler 20000mAh power bank ', 26000, 3);
    productsItem($ctx, $ctx['main'], 'Oraimo 10000mAh Power Bank', 15000, 4);

    $res = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => '20000mah power bank']);
    $item = $res['products'][0];

    expect($res['match'])->toBe('name')
        ->and($res['products_found'])->toBe(1)
        ->and($item['name'])->toBe('Oraimo Traveler 20000mAh Power Bank')
        ->and($item['total_available'])->toBe(10)
        ->and($item['price_differs_by_branch'])->toBeTrue()
        ->and(collect($item['branches'])->pluck('price_display', 'store')->all())
        ->toBe(['Main Store' => '₦25,000', 'Zeelink Test Ikeja' => '₦26,000'])
        ->and(collect($item['branches'])->pluck('available')->all())->toBe([7, 3]);
});

test('"list all the power banks we have in store": plural words match, and in-stock-only drops the empty ones', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);
    productsItem($ctx, $ctx['main'], 'Oraimo 10000mAh Power Bank', 15000, 0);
    productsItem($ctx, $ctx['main'], 'Anker USB-C Cable', 4000, 30);

    $all = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'power banks']);
    $inStock = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'power banks', 'in_stock_only' => true]);

    expect(collect($all['products'])->pluck('name')->sort()->values()->all())
        ->toBe(['Oraimo 10000mAh Power Bank', 'Oraimo Traveler 20000mAh Power Bank'])
        ->and(collect($inStock['products'])->pluck('name')->all())->toBe(['Oraimo Traveler 20000mAh Power Bank']);
});

test('a SKU or barcode finds the product, and the same item at the other branch with it', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Redmi 15C 128GB', 180000, 2, ['sku' => 'RDM-15C-128', 'barcode' => '6941812700000']);
    productsItem($ctx, $ctx['ikeja'], 'Redmi 15C 128GB', 182000, 5, ['sku' => 'RDM-15C-128-IKJ']);

    $bySku = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'RDM-15C-128']);
    $byBarcode = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => '6941812700000']);

    expect($bySku['match'])->toBe('exact_code')
        ->and($bySku['products'][0]['total_available'])->toBe(7)
        ->and(collect($bySku['products'][0]['branches'])->pluck('sku')->sort()->values()->all())->toBe(['RDM-15C-128', 'RDM-15C-128-IKJ'])
        ->and($byBarcode['match'])->toBe('exact_code');
});

test('a loose spelling is only a possible match, which the model is told to confirm', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);

    $res = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'powerbank 20000']);
    $none = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'unicorn charger']);

    expect($res['match'])->toBe('possible')
        ->and($res['products'][0]['name'])->toBe('Oraimo Traveler 20000mAh Power Bank')
        ->and($none['match'])->toBe('none')
        ->and($none['products'])->toBe([]);
});

test('half units, extra selling units, wholesale price and drafts are shown as they are', function () {
    $ctx = productsVendor();
    $crate = productsItem($ctx, $ctx['main'], 'Castle Lite Bottle', 900, 30.5, ['wholesale_price' => 850]);
    ProductUnit::create(['product_id' => $crate->id, 'vendor_id' => $ctx['vendor']->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 10200, 'is_base' => false, 'is_active' => true, 'sort_order' => 1]);
    productsItem($ctx, $ctx['main'], 'Castle Lite Can', 800, 5, ['status' => 'draft']);

    $res = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'castle lite']);
    $byName = collect($res['products'])->keyBy('name');
    $bottle = $byName['Castle Lite Bottle']['branches'][0];

    expect($bottle['available'])->toBe(30.5)
        ->and($bottle['wholesale_price_display'])->toBe('₦850')
        ->and($bottle['units'])->toBe([['unit' => 'Crate', 'contains' => 12, 'price' => 10200.0, 'price_display' => '₦10,200']])
        ->and($byName['Castle Lite Can']['branches'][0]['not_on_sale'])->toBeTrue();
});

test('staff see only their branch, and no cost without view_cost_price', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);
    productsItem($ctx, $ctx['ikeja'], 'Oraimo Traveler 20000mAh Power Bank', 26000, 3);
    $keeper = productsStaff($ctx, [$ctx['ikeja']], ['use_ai_assistant', 'view_any_products']);

    $res = productsRun($keeper, $ctx['vendor'], new FindProductsQuery, ['store_id' => $ctx['ikeja']->id], ['query' => 'power bank']);
    $branches = $res['products'][0]['branches'];

    $owner = productsRun($ctx['owner'], $ctx['vendor'], new FindProductsQuery, productsAll($ctx), ['query' => 'power bank']);

    expect(collect($branches)->pluck('store')->all())->toBe(['Zeelink Test Ikeja'])
        ->and($branches[0])->not->toHaveKey('cost_price')
        ->and($branches[0])->not->toHaveKey('cost_price_display')
        ->and($owner['products'][0]['branches'][0])->toHaveKey('cost_price')
        // And not the other branch by asking for it.
        ->and(fn () => productsRun($keeper, $ctx['vendor'], new FindProductsQuery, ['store_id' => $ctx['main']->id], ['query' => 'power bank']))
        ->toThrow(Exception::class);
});

test('without view_any_products, product questions are refused like the Products screen', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);
    $cashier = productsStaff($ctx, [$ctx['main']], ['use_ai_assistant']);

    $res = productsRun($cashier, $ctx['vendor'], new FindProductsQuery, ['store_id' => $ctx['main']->id], ['query' => 'power bank']);

    expect($res)->toBe(['has_data' => false, 'reason' => 'no_access']);
});

test('another business\'s products never appear, even with the same name', function () {
    $a = productsVendor('Shop A');
    $b = productsVendor('Shop B');
    productsItem($b, $b['main'], 'Oraimo Traveler 20000mAh Power Bank', 99999, 50);

    $res = productsRun($a['owner'], $a['vendor'], new FindProductsQuery, productsAll($a), ['query' => 'power bank']);

    expect($res['match'])->toBe('none');
});

test('low stock: each product\'s own level, every branch, halves kept, drafts and services left out', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Low Cable', 1000, 2.5);
    productsItem($ctx, $ctx['ikeja'], 'Low Earbuds', 9000, 1, ['low_stock_threshold' => 3]);
    productsItem($ctx, $ctx['main'], 'Plenty Cable', 1000, 40);
    productsItem($ctx, $ctx['main'], 'Draft Cable', 1000, 1, ['status' => 'draft']);
    productsItem($ctx, $ctx['main'], 'Repair Service', 5000, 1, ['is_service' => true]);
    productsItem($ctx, $ctx['main'], 'Empty Cable', 1000, 0);

    $res = productsRun($ctx['owner'], $ctx['vendor'], new LowStockQuery, productsAll($ctx));
    $override = productsRun($ctx['owner'], $ctx['vendor'], new LowStockQuery, productsAll($ctx), ['threshold' => 50]);

    expect($res['count'])->toBe(2)
        ->and(collect($res['items'])->map(fn ($i) => [$i['name'], $i['store'], $i['available']])->all())
        ->toBe([['Low Earbuds', 'Zeelink Test Ikeja', 1], ['Low Cable', 'Main Store', 2.5]])
        ->and(collect($override['items'])->pluck('name')->all())->toContain('Plenty Cable');
});

test('out of stock: every branch, real products only', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Empty Cable', 1000, 0);
    productsItem($ctx, $ctx['ikeja'], 'Empty Earbuds', 9000, 0);
    productsItem($ctx, $ctx['main'], 'Draft Empty', 1000, 0, ['status' => 'draft']);
    productsItem($ctx, $ctx['main'], 'Repair Service', 5000, 0, ['is_service' => true]);
    productsItem($ctx, $ctx['main'], 'Stocked Cable', 1000, 3);

    $res = productsRun($ctx['owner'], $ctx['vendor'], new OutOfStockQuery, productsAll($ctx));

    expect($res['count'])->toBe(2)
        ->and(collect($res['items'])->pluck('name')->all())->toBe(['Empty Cable', 'Empty Earbuds']);
});

test('through the model: the query reaches find_products and the answer gets the product', function () {
    $ctx = productsVendor();
    productsItem($ctx, $ctx['main'], 'Oraimo Traveler 20000mAh Power Bank', 25000, 7);

    $seen = new ArrayObject;
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('chat')->once()->andReturn([
        'content' => null, 'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'find_products', 'arguments' => json_encode(['query' => '20000mah power bank'])]]],
    ]);
    $client->shouldReceive('chat')->once()->andReturnUsing(function (array $messages) use ($seen) {
        $seen['payload'] = json_decode((string) end($messages)['content'], true);

        return ['content' => 'It is ₦25,000; 7 in stock.', 'tool_calls' => [], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]];
    });
    app()->instance(AiClient::class, $client);

    test()->actingAs($ctx['owner']);
    $thread = app(CreateThreadAction::class)->execute($ctx['owner'], $ctx['vendor']);
    app(SetThreadStoreAction::class)->execute($thread, $ctx['main']);
    app(SendMessageAction::class)->execute($ctx['owner'], $thread->fresh(), 'how much is 20000mah power bank?', null, $ctx['vendor']);

    expect($seen['payload']['products'][0]['price_display'])->toBe('₦25,000')
        ->and($seen['payload']['products'][0]['total_available'])->toBe(7)
        ->and($seen['payload'])->not->toHaveKey('period');
});
