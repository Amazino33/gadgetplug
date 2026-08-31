<?php

use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The products list could be searched and filtered by status, but not by
// category — the only category picker anywhere was inside the export modal.

function categoryFilterContext(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Filter Store ' . uniqid()]);
    $store  = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    $phones = Category::create(['name' => 'Phones ' . uniqid()]);
    $audio  = Category::create(['name' => 'Audio ' . uniqid()]);

    $make = fn (string $name, Category $category) => Product::create([
        'vendor_id' => $vendor->id, 'store_id' => $store->id,
        'category_id' => $category->id, 'name' => $name,
        'price' => 1000, 'cost_price' => 500,
        'stock_quantity' => 5, 'status' => 'published',
    ]);

    $iphone = $make('Filter iPhone', $phones);
    $galaxy = $make('Filter Galaxy', $phones);
    $buds   = $make('Filter Earbuds', $audio);

    $owner->stores()->syncWithoutDetaching([$store->id]);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('owner', 'vendor', 'store', 'phones', 'audio', 'iphone', 'galaxy', 'buds');
}

test('choosing a category narrows the list to it', function () {
    $ctx = categoryFilterContext();

    Livewire::test(ListProducts::class)
        ->set('categoryFilter', (string) $ctx['phones']->id)
        ->assertOk()
        ->assertSee('Filter iPhone')
        ->assertSee('Filter Galaxy')
        ->assertDontSee('Filter Earbuds');
});

test('no category selected shows everything', function () {
    categoryFilterContext();

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee('Filter iPhone')
        ->assertSee('Filter Earbuds');
});

test('the category filter narrows the grid view too, not only the table', function () {
    $ctx = categoryFilterContext();

    Livewire::test(ListProducts::class, ['displayMode' => 'grid'])
        ->set('categoryFilter', (string) $ctx['audio']->id)
        ->assertOk()
        ->assertSee('Filter Earbuds')
        ->assertDontSee('Filter iPhone');
});

test('category and status filters narrow together rather than replacing each other', function () {
    $ctx = categoryFilterContext();
    $ctx['galaxy']->update(['status' => 'draft']);

    Livewire::test(ListProducts::class)
        ->set('categoryFilter', (string) $ctx['phones']->id)
        ->set('statusFilter', 'published')
        ->assertOk()
        ->assertSee('Filter iPhone')
        ->assertDontSee('Filter Galaxy')     // right category, wrong status
        ->assertDontSee('Filter Earbuds');   // right status, wrong category
});

test('search and the category filter narrow together', function () {
    $ctx = categoryFilterContext();

    Livewire::test(ListProducts::class)
        ->set('categoryFilter', (string) $ctx['phones']->id)
        ->set('search', 'Galaxy')
        ->assertOk()
        ->assertSee('Filter Galaxy')
        ->assertDontSee('Filter iPhone');
});

test('the filter offers only categories this shop actually stocks', function () {
    $ctx = categoryFilterContext();

    // Another vendor's category, on the same shared platform table.
    $foreign = Category::create(['name' => 'Groceries ' . uniqid()]);
    $otherOwner = User::factory()->create();
    $otherVendor = Vendor::create(['user_id' => $otherOwner->id, 'name' => 'Other Store ' . uniqid()]);
    Product::create([
        'vendor_id' => $otherVendor->id,
        'store_id' => Store::where('vendor_id', $otherVendor->id)->value('id'),
        'category_id' => $foreign->id, 'name' => 'Someone Elses Rice',
        'price' => 100, 'stock_quantity' => 1, 'status' => 'published',
    ]);

    $options = Livewire::test(ListProducts::class)->instance()->getCategoryOptions();

    expect($options)->toHaveKey($ctx['phones']->id)
        ->and($options)->toHaveKey($ctx['audio']->id)
        // Offering it could only ever return an empty list.
        ->and($options)->not->toHaveKey($foreign->id);
});

test('changing the category returns to the first page instead of stranding you past the end', function () {
    $ctx = categoryFilterContext();

    // Enough audio to fill a second page, while Phones holds only two — so a
    // filter change while on page 2 would land beyond the end of the results.
    for ($i = 0; $i < 12; $i++) {
        Product::create([
            'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
            'category_id' => $ctx['audio']->id, 'name' => "Filler Speaker {$i}",
            'price' => 1000, 'stock_quantity' => 1, 'status' => 'published',
        ]);
    }

    Livewire::test(ListProducts::class)
        ->call('setPage', 2, 'productsPage')
        ->set('categoryFilter', (string) $ctx['phones']->id)
        ->assertOk()
        // Both phones are visible, so the page reset — without it this would be
        // an empty page 2 of a two-item result.
        ->assertSee('Filter iPhone')
        ->assertSee('Filter Galaxy');
});
