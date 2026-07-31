<?php

use App\Filament\Vendor\Pages\PriceList;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpPriceListVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Price List Store']);

    VendorRoles::seedFor($vendor);

    $staff = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');
    $vendor->users()->attach($staff->id);

    $phones      = Category::create(['name' => 'Phones']);
    $accessories = Category::create(['name' => 'Accessories']);

    $published = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $phones->id,
        'name' => 'Visible Phone', 'sku' => 'VIS-1',
        'price' => 250000, 'cost_price' => 180000,
        'stock_quantity' => 4, 'status' => 'published', 'published_at' => now(),
    ]);

    $outOfStock = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $accessories->id,
        'name' => 'Sold Out Case', 'sku' => 'OOS-1',
        'price' => 5000, 'cost_price' => 2000,
        'stock_quantity' => 0, 'status' => 'published', 'published_at' => now(),
    ]);

    $draft = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $phones->id,
        'name' => 'Secret Draft Phone', 'sku' => 'DRF-1',
        'price' => 99000, 'cost_price' => 50000,
        'stock_quantity' => 3, 'status' => 'draft',
    ]);

    return compact('owner', 'vendor', 'staff', 'published', 'outOfStock', 'draft');
}

function actAsPriceListStaff(array $data): void
{
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('the page lists published products and hides drafts', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    Livewire::test(PriceList::class)
        ->assertSee('Visible Phone')
        ->assertSee('Sold Out Case')
        ->assertDontSee('Secret Draft Phone');
});

// The sheet is built to be forwarded and screenshotted, so margin data must not
// reach the markup at all.
test('cost price never appears on the page', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    Livewire::test(PriceList::class)
        ->assertDontSee('180,000')
        ->assertDontSee('180000')
        ->assertSee('250,000.00');
});

test('out of stock products are listed but marked', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    Livewire::test(PriceList::class)
        ->assertSee('Sold Out Case')
        ->assertSee('out of stock');
});

test('products are grouped by category', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    $grouped = Livewire::test(PriceList::class)->instance()->getGroupedProducts();

    expect($grouped->keys()->all())->toBe(['Accessories', 'Phones'])
        ->and($grouped['Phones']->pluck('name')->all())->toBe(['Visible Phone']);
});

test('search filters the screen but the pdf still gets everything', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    $page = Livewire::test(PriceList::class)->set('search', 'Sold Out')->instance();

    expect($page->getGroupedProducts(applySearch: true)->flatten()->count())->toBe(1)
        ->and($page->getGroupedProducts()->flatten()->count())->toBe(2);
});

test('the download button is wired up to a file download', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    Livewire::test(PriceList::class)
        ->call('downloadPdf')
        ->assertFileDownloaded();
});

test('the generated file is a real pdf containing the products', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    $response = (new PriceList())->downloadPdf();

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    // %PDF- magic bytes, and big enough to be more than an empty shell
    expect($body)->toStartWith('%PDF-')
        ->and(strlen($body))->toBeGreaterThan(1000);
});

function bulkProducts(array $data, int $count, string $namePattern = 'Bulk Item %02d'): void
{
    $category = Category::first();

    foreach (range(1, $count) as $i) {
        Product::create([
            'vendor_id' => $data['vendor']->id, 'category_id' => $category->id,
            'name' => sprintf($namePattern, $i), 'sku' => "BULK-{$i}",
            'price' => 1000 + $i, 'cost_price' => 500,
            'stock_quantity' => 5, 'status' => 'published', 'published_at' => now(),
        ]);
    }
}

// Pagination is done in PHP because dompdf splits an overflowing table row very
// badly. Every column must open with a heading, so prices never sit under none.
test('every column opens with a heading, carried over when a category spills', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    bulkProducts($data, 60);

    $page  = new PriceList();
    $pages = $page->buildPages($page->getGroupedProducts());

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $columns) {
        foreach (array_filter($columns) as $column) {
            expect($column[0]['type'])->toBe('header');
        }
    }

    $carried = collect($pages)->flatten(2)->filter(fn ($r) => str_contains($r['text'] ?? '', '(cont.)'));
    expect($carried)->not->toBeEmpty();
});

test('a short list is balanced across the three columns rather than crammed into one', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    bulkProducts($data, 30);

    $page  = new PriceList();
    $pages = $page->buildPages($page->getGroupedProducts());

    expect($pages)->toHaveCount(1);

    $sizes = array_map('count', array_filter($pages[0]));

    expect(count($sizes))->toBe(3)
        // No column may hold more than roughly double the lightest one
        ->and(max($sizes))->toBeLessThanOrEqual(2 * min($sizes));
});

// A column that overflows its budget is what produced blank pages and stranded
// columns before pagination moved into PHP.
test('no column exceeds the calibrated height budget', function () {
    $data = setUpPriceListVendor();
    $this->actingAs($data['staff']);
    actAsPriceListStaff($data);

    bulkProducts($data, 250, 'Long Product Name Number %02d 128GB Dual SIM');

    $page  = new PriceList();
    $pages = $page->buildPages($page->getGroupedProducts());

    expect(count($pages))->toBeGreaterThan(1);

    foreach ($pages as $columns) {
        expect(count($columns))->toBeLessThanOrEqual(PriceList::COLUMNS_PER_PAGE);

        foreach (array_filter($columns) as $column) {
            $units = array_sum(array_column($column, 'units'));
            expect($units)->toBeLessThanOrEqual(PriceList::COLUMN_CAPACITY);
        }
    }
});

test('a user without view_products cannot access the page', function () {
    $data = setUpPriceListVendor();

    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    actAsPriceListStaff($data);

    expect(PriceList::canAccess())->toBeFalse();
});
