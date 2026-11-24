<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSearchShortcut;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Products\SearchShortcuts;
use App\Support\ProductSearch;
use Laravel\Sanctum\Sanctum;

// Product search on the till: words in any order,
// best sellers first, and the shop's own words — typed or learned.

function searchShop(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Search Shop', 'pos_min_margin_percent' => 0]);
    $category = Category::create(['name' => 'Groceries']);

    $make = fn (string $name, array $extra = []) => Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => $name,
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 40,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_in_pos'    => true,
    ], $extra));

    return [
        'owner'    => $owner,
        'vendor'   => $vendor,
        'peakTin'  => $make('PEAK MILK TIN 400G'),
        'peakSac'  => $make('PEAK MILK SACHET 16G'),
        'coke'     => $make('COCA-COLA 50CL', ['search_keywords' => 'coke, coca']),
        'mackerel' => $make('MACKEREL FISH (LARGE)'),
    ];
}

function tillSearch(array $shop, string $q): array
{
    Sanctum::actingAs($shop['owner']);

    return test()->getJson('/api/pos/products/search?'.http_build_query(['vendor_id' => $shop['vendor']->id, 'q' => $q]))
        ->assertOk()
        ->json();
}

test('words are cleaned the same way the browser cleans them', function () {
    expect(ProductSearch::words('COCA-COLA 50CL'))->toBe(['coca', 'cola', '50', 'cl'])
        ->and(ProductSearch::words("Women's Hair"))->toBe(['womens', 'hair'])
        ->and(ProductSearch::words('Dark & Lovely'))->toBe(['dark', 'and', 'lovely'])
        ->and(ProductSearch::words('  '))->toBe([]);
});

test('the till finds a product by words in any order', function () {
    $shop = searchShop();

    expect(collect(tillSearch($shop, 'peak 400'))->pluck('name')->all())->toBe(['PEAK MILK TIN 400G'])
        ->and(collect(tillSearch($shop, 'milk peak'))->pluck('name')->sort()->values()->all())->toBe(['PEAK MILK SACHET 16G', 'PEAK MILK TIN 400G'])
        ->and(collect(tillSearch($shop, 'coca cola 50'))->pluck('name')->all())->toBe(['COCA-COLA 50CL']);
});

test("the till finds a product by the shop's keywords, and sends them to the device", function () {
    $shop = searchShop();

    $found = tillSearch($shop, 'coke');

    expect(collect($found)->pluck('name')->all())->toBe(['COCA-COLA 50CL'])
        ->and(collect($found[0]['search_terms'])->pluck('term')->all())->toBe(['coke', 'coca']);
});

test('a learned word counts only once staff have used it twice', function () {
    $shop = searchShop();
    Sanctum::actingAs($shop['owner']);
    $learn = fn () => test()->postJson("/api/pos/products/{$shop['mackerel']->id}/search-shortcut", [
        'vendor_id' => $shop['vendor']->id,
        'term'      => 'Titus',
    ])->assertOk();

    $learn();
    expect(tillSearch($shop, 'titus'))->toBe([]);

    $learn();
    $found = tillSearch($shop, 'titus');

    expect(collect($found)->pluck('name')->all())->toBe(['MACKEREL FISH (LARGE)'])
        ->and($found[0]['search_terms'])->toBe([['term' => 'titus', 'hits' => 2]]);
});

test('a code, or another shop\'s product, is never learned', function () {
    $shop = searchShop();
    $other = searchShop();
    Sanctum::actingAs($shop['owner']);

    test()->postJson("/api/pos/products/{$shop['mackerel']->id}/search-shortcut", ['vendor_id' => $shop['vendor']->id, 'term' => '6001234'])->assertOk();
    test()->postJson("/api/pos/products/{$other['mackerel']->id}/search-shortcut", ['vendor_id' => $shop['vendor']->id, 'term' => 'titus'])->assertOk();

    expect(ProductSearchShortcut::count())->toBe(0);
});

test('the catalogue carries how often each product sold at the branch, and search puts best sellers first', function () {
    $shop = searchShop();
    Sanctum::actingAs($shop['owner']);

    foreach (range(1, 3) as $i) {
        test()->postJson('/api/pos/sales', [
            'vendor_id'       => $shop['vendor']->id,
            'items'           => [['product_id' => $shop['peakSac']->id, 'product_name' => 'PEAK MILK SACHET 16G', 'unit_price' => 1000, 'quantity' => 1]],
            'payment_method'  => 'cash',
            'amount_tendered' => 1000,
            'vat_rate'        => 0,
        ])->assertSuccessful();
    }
    cache()->flush();

    $catalogue = collect(test()->getJson('/api/pos/products?vendor_id='.$shop['vendor']->id.'&after_id=0&limit=500')->assertOk()->json())->keyBy('name');

    expect($catalogue['PEAK MILK SACHET 16G']['sold_30d'])->toBe(3)
        ->and($catalogue['PEAK MILK TIN 400G']['sold_30d'])->toBe(0)
        ->and(tillSearch($shop, 'peak')[0]['name'])->toBe('PEAK MILK SACHET 16G');
});

test('keywords are tidied into a list', function () {
    expect(SearchShortcuts::keywords(" coke ,Coca;; COKE\nsoda "))->toBe(['coke', 'Coca', 'soda'])
        ->and(SearchShortcuts::clean('Titus!'))->toBe('titus')
        ->and(SearchShortcuts::clean('600123'))->toBeNull()
        ->and(SearchShortcuts::clean('a'))->toBeNull();
});
