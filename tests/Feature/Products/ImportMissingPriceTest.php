<?php

use App\Models\Product;
use App\Services\Import\ParsedRow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\User;
use App\Models\Vendor;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\ProductImporter;
use App\Services\Import\SpreadsheetReader;

// Self-contained rather than borrowed from ProductImportTest: helpers only
// resolve across Pest files when the other file happens to load first, and
// that suite is being actively edited elsewhere.
function priceTestVendor(): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Price Test Store']);
}

function priceTestCsv(string $contents): string
{
    $dir = storage_path('app/testing-imports');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir . '/' . uniqid() . '-missing-price.csv';
    file_put_contents($path, $contents);

    return $path;
}

function priceTestPrepare(string $path, Vendor $vendor)
{
    $reader  = app(SpreadsheetReader::class);
    $mapping = app(ColumnMapper::class)->guess($reader->headers($path));

    return app(ImportPreparer::class)->prepare($path, $mapping, $vendor);
}

function priceTestRun(string $path, Vendor $vendor)
{
    return app(ProductImporter::class)->commit(
        priceTestPrepare($path, $vendor), $vendor, null, basename($path)
    );
}


// products.price is NOT NULL with no default. A blank price cell raises no
// parse error — an empty cell rightly means "column not supplied" rather than
// bad data — so before this check the row previewed as a healthy Create and
// then killed the entire import on a raw SQL error. With 200-row files that
// meant fixing one cell, re-running everything, and hitting the next one.

it('flags a new product with no price instead of letting it reach the database', function () {
    $vendor = priceTestVendor();

    $path = priceTestCsv(<<<CSV
    name,sku,price,cost_price
    Good Charger,SHP-0001,2000,700
    Shplus Charger SH A432T,SHP-0153,,
    Another Good One,SHP-0002,3000,900
    CSV);

    $rows = priceTestPrepare($path, $vendor);

    $priceless = $rows->firstWhere(fn (ParsedRow $r) => $r->value('sku') === 'SHP-0153');

    expect($priceless->action())->toBe(ParsedRow::ACTION_SKIP)
        ->and($priceless->errors)->toContain(
            'Price is missing. A new product needs one, because it cannot be sold without a price.'
        );
});

it('imports the good rows and skips only the priceless one', function () {
    $vendor = priceTestVendor();

    $path = priceTestCsv(<<<CSV
    name,sku,price,cost_price
    Good Charger,SHP-0001,2000,700
    Shplus Charger SH A432T,SHP-0153,,
    Another Good One,SHP-0002,3000,900
    CSV);

    $log = priceTestRun($path, $vendor);

    // The whole point: a successful run that reports what it could not take,
    // rather than an aborted one that changed nothing.
    expect($log->status)->toBe('completed')
        ->and($log->created_count)->toBe(2)
        ->and($log->skipped_count)->toBe(1)
        ->and(Product::where('vendor_id', $vendor->id)->pluck('sku')->all())
        ->toBe(['SHP-0001', 'SHP-0002']);
});

it('reports every priceless row at once, not one per attempt', function () {
    $vendor = priceTestVendor();

    $path = priceTestCsv(<<<CSV
    name,sku,price
    First Bad,BAD-1,
    Fine One,OK-1,1500
    Second Bad,BAD-2,
    Third Bad,BAD-3,
    CSV);

    $rows = priceTestPrepare($path, $vendor);

    $skipped = $rows->filter(fn (ParsedRow $r) => $r->action() === ParsedRow::ACTION_SKIP);

    // Three visible on the first look, so the spreadsheet gets fixed once.
    expect($skipped)->toHaveCount(3)
        ->and($skipped->map(fn (ParsedRow $r) => $r->value('sku'))->values()->all())
        ->toBe(['BAD-1', 'BAD-2', 'BAD-3']);
});

it('still lets an update through without a price, leaving the existing one alone', function () {
    $vendor = priceTestVendor();

    $existing = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name'        => 'Existing Charger',
        'sku'         => 'SHP-0500',
        'price'       => 4500,
        'status'      => 'published',
    ]);

    // A stock-only update file: no price column at all.
    $path = priceTestCsv(<<<CSV
    name,sku,stock_quantity
    Existing Charger,SHP-0500,25
    CSV);

    $rows = priceTestPrepare($path, $vendor);
    $row  = $rows->first();

    expect($row->action())->toBe(ParsedRow::ACTION_UPDATE);

    priceTestRun($path, $vendor);

    // Untouched — an update with no price column must not disturb the price.
    expect((float) $existing->fresh()->price)->toBe(4500.0);
});

it('still treats a malformed price as bad data rather than a missing one', function () {
    $vendor = priceTestVendor();

    $path = priceTestCsv(<<<CSV
    name,sku,price
    Nonsense Price,NON-1,not a number
    CSV);

    $row = priceTestPrepare($path, $vendor)->first();

    // Already handled by the parser; the new check must not swallow or
    // duplicate that message.
    expect($row->action())->toBe(ParsedRow::ACTION_SKIP)
        ->and(implode(' ', $row->errors))->toContain('is not a valid');
});
