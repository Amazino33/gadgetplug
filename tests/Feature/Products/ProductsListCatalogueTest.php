<?php

use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Products\ProductCatalogue;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The Products list keeps the whole branch in the browser: one download
 * (ProductCatalogueController), then search, the summary tiles, filters,
 * sorting and paging happen there. These check what the download carries,
 * who may have it, and the bulk actions the list sends back.
 */

function plShop(string $name = 'List Shop'): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name . ' ' . uniqid()]);
    VendorRoles::seedFor($vendor);

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $vendor->defaultStore, 'category' => Category::firstOrCreate(['name' => 'List Category'])];
}

function plProduct(array $shop, string $name, array $over = [], float $onShelf = 10): Product
{
    $product = Product::create(array_merge([
        'vendor_id' => $shop['vendor']->id, 'store_id' => $shop['store']->id, 'category_id' => $shop['category']->id,
        'name' => $name, 'sku' => strtoupper(substr(md5($name), 0, 6)), 'price' => 1000, 'cost_price' => 600,
        'stock_quantity' => $onShelf, 'status' => 'published', 'low_stock_threshold' => 5,
    ], $over));

    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $shop['store']->id], ['quantity' => $onShelf, 'reserved' => 0]);

    return $product;
}

function plStorekeeper(array $shop): User
{
    $keeper = User::factory()->create();
    $shop['vendor']->users()->syncWithoutDetaching([$keeper->id]);
    $keeper->stores()->syncWithoutDetaching([$shop['store']->id]);
    setPermissionsTeamId($shop['vendor']->id);
    $keeper->assignRole('storekeeper');

    return $keeper;
}

function plUrl(array $shop): string
{
    return "/vendor-api/{$shop['vendor']->slug}/products-catalogue";
}

/** A row of the download as name => value. */
function plRow(array $data, int $id): array
{
    $row = collect($data['rows'])->first(fn ($r) => $r[0] === $id);

    return array_combine($data['columns'], $row);
}

test('the download holds every product of the branch, with stock, photo, barcode and sales facts', function () {
    $shop = plShop();
    $milk = plProduct($shop, 'Peak Milk', ['barcode' => '6001'], 12);
    $rice = plProduct($shop, 'Rice 5kg', [], 0);
    foreach (range(1, 60) as $i) {
        plProduct($shop, "Filler {$i}");
    }

    $data = $this->actingAs($shop['owner'])->getJson(plUrl($shop))->assertOk()->json();

    expect($data['rows'])->toHaveCount(62)
        ->and($data['columns'])->toBe(ProductCatalogue::COLUMNS)
        ->and($data['categories'])->toHaveKey((string) $shop['category']->id);

    $m = plRow($data, $milk->id);
    expect($m)->toMatchArray(['name' => 'Peak Milk', 'available' => 12, 'quantity' => 12, 'low_at' => 5, 'cost' => 600, 'has_barcode' => true, 'photo' => null])
        ->and($m['search'])->toContain('peak milk')->toContain('6001');

    expect(plRow($data, $rice->id))->toMatchArray(['available' => 0, 'has_barcode' => false]);
});

test('when each product last sold, and how many this week', function () {
    $shop = plShop();
    $milk = plProduct($shop, 'Peak Milk');

    $sale = DB::table('pos_sales')->insertGetId([
        'vendor_id' => $shop['vendor']->id, 'store_id' => $shop['store']->id, 'status' => 'completed',
        'cashier_id' => $shop['owner']->id, 'reference' => 'R-1', 'subtotal' => 3000, 'total' => 3000, 'payment_method' => 'cash',
        'completed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_sale_items')->insert([
        'pos_sale_id' => $sale, 'product_id' => $milk->id, 'product_name' => 'Peak Milk', 'unit_price' => 1000,
        'quantity' => 3, 'total' => 3000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = $this->actingAs($shop['owner'])->getJson(plUrl($shop))->assertOk()->json();

    expect(plRow($data, $milk->id))->toMatchArray(['sold_7d' => 3])
        ->and(plRow($data, $milk->id)['last_sold'])->toBeGreaterThan(now()->subDays(3)->getTimestamp());
});

test('cost prices only for someone allowed to see them', function () {
    $shop = plShop();
    $milk = plProduct($shop, 'Peak Milk');
    $keeper = plStorekeeper($shop);

    $data = $this->actingAs($keeper)->getJson(plUrl($shop))->assertOk()->json();

    expect(plRow($data, $milk->id)['cost'])->toBeNull()
        ->and(json_encode($data))->not->toContain('"cost_price"');
});

test('another shop\'s people get nothing; a stranger gets nothing', function () {
    $shop  = plShop();
    plProduct($shop, 'Peak Milk');
    $other = plShop('Other');

    $this->actingAs($other['owner'])->getJson(plUrl($shop))->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson(plUrl($shop))->assertNotFound();
    auth()->logout();
    $this->getJson(plUrl($shop))->assertUnauthorized();
});

test('"has anything changed?" is answered cheaply, and a change gives a new version', function () {
    $shop = plShop();
    $milk = plProduct($shop, 'Peak Milk');

    $first = $this->actingAs($shop['owner'])->getJson(plUrl($shop))->json('v');

    $this->getJson(plUrl($shop) . "?v={$first}")->assertOk()->assertExactJson(['same' => true, 'v' => $first]);

    $this->travel(2)->seconds();
    ProductStoreStock::where('product_id', $milk->id)->update(['quantity' => 3, 'updated_at' => now()]);

    $after = $this->getJson(plUrl($shop) . "?v={$first}")->assertOk()->json();
    expect($after['v'])->not->toBe($first)
        ->and(plRow($after, $milk->id)['available'])->toBe(3);
});

function plPanelAs(array $shop, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($shop['vendor']);
}

test('bulk: publish, move category and set the low-stock level for many products at once', function () {
    $shop = plShop();
    $a = plProduct($shop, 'A', ['status' => 'draft']);
    $b = plProduct($shop, 'B', ['status' => 'draft']);
    $other = plShop('Other');
    $theirs = plProduct($other, 'Theirs', ['status' => 'draft']);
    $drinks = Category::firstOrCreate(['name' => 'Drinks']);

    plPanelAs($shop, $shop['owner']);
    $page = Livewire::test(ListProducts::class);

    expect($page->instance()->bulkUpdate([$a->id, $b->id, $theirs->id], 'status', 'published'))->toMatchArray(['ok' => true, 'changed' => 2]);
    $page->instance()->bulkUpdate([$a->id], 'category_id', $drinks->id);
    $page->instance()->bulkUpdate([$a->id, $b->id], 'low_stock_threshold', 12);

    expect($a->fresh())->status->toBe('published')->category_id->toBe($drinks->id)->low_stock_threshold->toBe(12)
        ->and($b->fresh()->status)->toBe('published')
        ->and($theirs->fresh()->status)->toBe('draft'); // another shop's product is never touched
});

test('bulk: a field that is not allowed, or a bad value, changes nothing', function () {
    $shop = plShop();
    $a = plProduct($shop, 'A', ['price' => 1000]);

    plPanelAs($shop, $shop['owner']);
    $page = Livewire::test(ListProducts::class)->instance();

    expect($page->bulkUpdate([$a->id], 'price', 1))->toMatchArray(['ok' => false])
        ->and($page->bulkUpdate([$a->id], 'status', 'deleted'))->toMatchArray(['ok' => false])
        ->and($page->bulkUpdate([$a->id], 'low_stock_threshold', -3))->toMatchArray(['ok' => false])
        ->and((float) $a->fresh()->price)->toBe(1000.0);
});

test('bulk changes need permission to edit products', function () {
    $shop = plShop();
    $a = plProduct($shop, 'A', ['status' => 'draft']);
    $keeper = plStorekeeper($shop);

    plPanelAs($shop, $keeper);
    $result = Livewire::test(ListProducts::class)->instance()->bulkUpdate([$a->id], 'status', 'published');

    expect($result['ok'])->toBeFalse()
        ->and($a->fresh()->status)->toBe('draft');
});

test('bulk delete removes only this shop\'s products', function () {
    $shop = plShop();
    $a = plProduct($shop, 'A');
    $b = plProduct($shop, 'B');
    $other = plShop('Other');
    $theirs = plProduct($other, 'Theirs');

    plPanelAs($shop, $shop['owner']);
    Livewire::test(ListProducts::class)->instance()->bulkDelete([$a->id, $theirs->id]);

    expect(Product::find($a->id))->toBeNull()
        ->and(Product::find($b->id))->not->toBeNull()
        ->and(Product::find($theirs->id))->not->toBeNull();
});

test('the page opens with the first 50 rows and where to fetch the rest', function () {
    $shop = plShop();
    foreach (range(1, 70) as $i) {
        plProduct($shop, "Item {$i}");
    }

    plPanelAs($shop, $shop['owner']);
    $config = Livewire::test(ListProducts::class)->instance()->getListConfig();

    expect($config['initial']['rows'])->toHaveCount(ListProducts::FIRST_PAGE)
        ->and($config['initial']['total'])->toBe(70)
        ->and($config['catalogueUrl'])->toEndWith("/vendor-api/{$shop['vendor']->slug}/products-catalogue")
        ->and($config['can'])->toMatchArray(['cost' => true, 'edit' => true, 'delete' => true]);
});
