<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The Zelink Tech incident, reproduced as a warning instead of a silent
// overwrite: a battery model number like "BL-5C" is a manufacturer part
// number, not a SKU this vendor chose, so two completely different physical
// products can legitimately end up sharing it. The old behaviour matched and
// overwrote without a trace; this fires a warning the vendor sees before
// confirming the import, instead of discovering it weeks later as "the price
// keeps changing".

function collisionVendor(): Vendor
{
    $owner = User::factory()->create();

    return Vendor::create(['user_id' => $owner->id, 'name' => 'Collision Test Store']);
}

function collisionPrepare(string $path, Vendor $vendor)
{
    $reader  = app(SpreadsheetReader::class);
    $mapping = app(ColumnMapper::class)->guess($reader->headers($path));

    return app(ImportPreparer::class)->prepare($path, $mapping, $vendor);
}

function collisionCsvFile(string $contents): string
{
    $dir = storage_path('app/testing-imports');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.uniqid().'-collision.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('warns when a matched SKU belongs to a product with an unrelated name', function () {
    $vendor   = collisionVendor();
    $category = Category::create(['name' => 'Batteries', 'slug' => 'batteries-'.uniqid()]);

    Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'itel A1481', 'sku' => 'BL-5C', 'price' => 3500,
    ]);

    $rows = collisionPrepare(collisionCsvFile("Name,SKU,Price\nP204 20000MAH Powerbank,BL-5C,42000\n"), $vendor);

    expect($rows->first()->warnings)->toContain(
        'This matches an existing product named "itel A1481" (#'.Product::first()->id.'), but the names look unrelated. If this is actually a different product, a shared SKU/barcode will silently overwrite "itel A1481" instead of creating a new one.'
    );
});

it('does not warn on a genuine rename or small wording change', function () {
    $vendor   = collisionVendor();
    $category = Category::create(['name' => 'Cases', 'slug' => 'cases-'.uniqid()]);

    Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'iPhone 13 Case Clear', 'sku' => 'CASE-13', 'price' => 2000,
    ]);

    $rows = collisionPrepare(collisionCsvFile("Name,SKU,Price\niPhone 13 Case (Clear),CASE-13,2200\n"), $vendor);

    expect($rows->first()->warnings)->toBe([]);
});

it('does not warn on a brand new product with no match at all', function () {
    $vendor = collisionVendor();

    $rows = collisionPrepare(collisionCsvFile("Name,SKU,Price\nSomething New,NEW-SKU,1000\n"), $vendor);

    expect($rows->first()->warnings)->toBe([]);
});
