<?php

use App\Models\Category;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Export\ProductExporter;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\ParsedRow;
use App\Services\Import\ProductImporter;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Vendors onboard from Aronium, Loyverse and hand-typed spreadsheets with
// hundreds of products already entered. These cover the ways that goes wrong.

function importVendor(): Vendor
{
    $owner = User::factory()->create();

    return Vendor::create(['user_id' => $owner->id, 'name' => 'Chip Gadget']);
}

/** Writes a CSV to a scratch path and returns it. */
function csvFile(string $contents, string $name = 'import.csv'): string
{
    $dir = storage_path('app/testing-imports');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.uniqid().'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}

/** The real Aronium header row, in its real order. */
function aroniumCsv(string ...$rows): string
{
    $header = 'Name,ProductGroup,SKU,Barcode,MeasurementUnit,Cost,Markup,Price,Tax,'
        .'IsTaxInclusivePrice,IsPriceChangeAllowed,IsUsingDefaultQuantity,IsService,'
        .'IsEnabled,Description,Quantity,Supplier,ReorderPoint,PreferredQuantity,'
        .'LowStockWarning,WarningQuantity';

    return implode("\n", [$header, ...$rows])."\n";
}

function prepareImport(string $path, Vendor $vendor, ?array $mapping = null)
{
    $reader  = app(SpreadsheetReader::class);
    $mapping ??= app(ColumnMapper::class)->guess($reader->headers($path));

    return app(ImportPreparer::class)->prepare($path, $mapping, $vendor);
}

function runImport(string $path, Vendor $vendor, ?array $mapping = null): ImportLog
{
    $rows = prepareImport($path, $vendor, $mapping);

    return app(ProductImporter::class)->commit($rows, $vendor, null, basename($path));
}

// ── Column mapping ───────────────────────────────────────────────────────────

it('guesses an Aronium export without being told anything about Aronium', function () {
    $headers = explode(',', explode("\n", aroniumCsv())[0]);

    $mapping = app(ColumnMapper::class)->guess($headers);

    expect($mapping)->toMatchArray([
        'Name'              => 'name',
        'ProductGroup'      => 'category',
        'SKU'               => 'sku',
        'Barcode'           => 'barcode',
        'MeasurementUnit'   => 'measurement_unit',
        'Cost'              => 'cost_price',
        'Price'             => 'price',
        'IsService'         => 'is_service',
        'IsEnabled'         => 'status',
        'Description'       => 'description',
        'Supplier'          => 'supplier',
        'ReorderPoint'      => 'reorder_point',
        'PreferredQuantity' => 'preferred_quantity',
        'WarningQuantity'   => 'low_stock_threshold',
    ]);
});

it('maps headers from a POS it has never seen, by shape alone', function () {
    $mapping = app(ColumnMapper::class)->guess([
        'Item Name', 'Product Code', 'Buy Price', 'Sell Price', 'Min Stock', 'Department',
    ]);

    expect($mapping)->toBe([
        'Item Name'    => 'name',
        'Product Code' => 'sku',
        'Buy Price'    => 'cost_price',
        'Sell Price'   => 'price',
        'Min Stock'    => 'reorder_point',
        'Department'   => 'category',
    ]);
});

it('never offers stock as something an import can write', function () {
    $mapping = app(ColumnMapper::class)->guess(['Name', 'SKU', 'Quantity', 'Stock On Hand']);

    expect($mapping)->not->toHaveKey('Quantity')
        ->and($mapping)->not->toHaveKey('Stock On Hand')
        ->and(array_values($mapping))->not->toContain('quantity');
});

it('does not let two columns claim the same field', function () {
    $mapping = app(ColumnMapper::class)->guess(['Price', 'Sale Price', 'Retail Price']);

    expect(array_values($mapping))->toBe(array_unique(array_values($mapping)));
});

// ── A fresh import ───────────────────────────────────────────────────────────

it('imports a whole Aronium file into an empty catalogue', function () {
    $vendor = importVendor();

    $path = csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,7500,46.6,11000,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
        'USB-C Cable 2m,Cables,USB-C-2M,6009880222,pcs,1200,66.6,2000,0,False,True,False,False,True,Braided cable,40,Lagos Electronics,20,100,True,8',
    ));

    $log = runImport($path, $vendor);

    expect($log->created_count)->toBe(2)
        ->and($log->updated_count)->toBe(0)
        ->and($log->skipped_count)->toBe(0)
        ->and($log->status)->toBe('completed');

    $charger = Product::where('sku', 'ANK-20W')->firstOrFail();

    expect($charger->name)->toBe('Anker 20W Charger')
        ->and((float) $charger->cost_price)->toBe(7500.0)
        ->and((float) $charger->price)->toBe(11000.0)
        ->and($charger->measurement_unit)->toBe('pcs')
        ->and($charger->reorder_point)->toBe(10)
        ->and($charger->preferred_quantity)->toBe(50)
        ->and($charger->low_stock_threshold)->toBe(5)
        ->and($charger->is_service)->toBeFalse()
        ->and($charger->status)->toBe('published')
        ->and($charger->category->name)->toBe('Chargers')
        ->and($charger->supplier->name)->toBe('Lagos Electronics');
});

it('leaves stock at zero even though the file states a quantity', function () {
    $vendor = importVendor();

    $path = csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,7500,46.6,11000,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
    ));

    runImport($path, $vendor);

    // 14 units are in the file. Writing them would desync the per-store mirror
    // and leave a stock movement with no ledger entry behind it.
    expect(Product::firstOrFail()->stock_quantity)->toBe(0);
});

it('creates each category and supplier once, however many rows name them', function () {
    $vendor = importVendor();

    $rows = [];

    for ($i = 1; $i <= 20; $i++) {
        $rows[] = "Product {$i},Chargers,SKU-{$i},,pcs,100,0,200,0,False,True,False,False,True,,0,Lagos Electronics,,,,";
    }

    runImport(csvFile(aroniumCsv(...$rows)), $vendor);

    expect(Category::where('name', 'Chargers')->count())->toBe(1)
        ->and(Supplier::where('vendor_id', $vendor->id)->count())->toBe(1);
});

it('reuses an existing category rather than creating one that differs only in case', function () {
    $vendor = importVendor();
    $existing = Category::create(['name' => 'Chargers', 'slug' => 'chargers']);

    runImport(csvFile(aroniumCsv(
        'A,CHARGERS,SKU-A,,pcs,100,0,200,0,False,True,False,False,True,,0,,,,,',
        'B,chargers,SKU-B,,pcs,100,0,200,0,False,True,False,False,True,,0,,,,,',
    )), $vendor);

    expect(Category::where('name', 'Chargers')->count())->toBe(1)
        ->and(Product::pluck('category_id')->unique()->all())->toBe([$existing->id]);
});

// ── Re-import ────────────────────────────────────────────────────────────────

it('updates the matching product instead of creating a duplicate', function () {
    $vendor = importVendor();

    $path = csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,7500,46.6,11000,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
    ));

    runImport($path, $vendor);

    $repriced = csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,8000,50,13500,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
    ));

    $log = runImport($repriced, $vendor);

    expect($log->created_count)->toBe(0)
        ->and($log->updated_count)->toBe(1)
        ->and(Product::count())->toBe(1)
        ->and((float) Product::firstOrFail()->price)->toBe(13500.0);
});

it('matches on barcode when the row carries no SKU', function () {
    $vendor = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    $existing = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => $category->id,
        'name'        => 'Old name',
        'barcode'     => '6009880222',
        'price'       => 1000,
    ]);

    runImport(csvFile("Name,Barcode,Price\nUSB-C Cable 2m,6009880222,2000\n"), $vendor);

    expect(Product::count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('USB-C Cable 2m')
        ->and((float) $existing->fresh()->price)->toBe(2000.0);
});

it('refuses a row whose SKU and barcode belong to two different products', function () {
    $vendor   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    $bySku = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Holds the SKU', 'sku' => 'THE-SKU', 'price' => 100,
    ]);

    $byBarcode = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Holds the barcode', 'barcode' => 'THE-BARCODE', 'price' => 100,
    ]);

    $rows = prepareImport(csvFile(
        "Name,SKU,Barcode,Price\nConflict,THE-SKU,THE-BARCODE,999\nFine,OTHER-SKU,,50\n"
    ), $vendor);

    // Applying it would move the barcode off the product that holds it, which
    // the unique index refuses - and a database error mid-run would abort the
    // entire import rather than this one row.
    expect($rows->first()->errors)->toHaveCount(1)
        ->and($rows->first()->errors[0])->toContain("Barcode \"THE-BARCODE\" already belongs to a different product (#{$byBarcode->id})")
        ->and($rows->first()->errors[0])->toContain("this row matches product #{$bySku->id}");

    // And the rest of the file is unaffected.
    $log = app(ProductImporter::class)->commit($rows, $vendor, null, 'conflict.csv');

    expect($log->created_count)->toBe(1)
        ->and($log->skipped_count)->toBe(1)
        ->and($bySku->fresh()->name)->toBe('Holds the SKU')
        ->and($byBarcode->fresh()->name)->toBe('Holds the barcode');
});

it('prefers SKU over barcode when only the SKU is already taken', function () {
    $vendor   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    $bySku = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Matched by SKU', 'sku' => 'THE-SKU', 'price' => 100,
    ]);

    // A SKU is chosen by the business; a barcode is whatever the manufacturer
    // printed. The SKU decides the match, and an unclaimed barcode comes along.
    runImport(csvFile("Name,SKU,Barcode,Price\nWinner,THE-SKU,FREE-BARCODE,999\n"), $vendor);

    expect($bySku->fresh()->name)->toBe('Winner')
        ->and($bySku->fresh()->barcode)->toBe('FREE-BARCODE')
        ->and(Product::count())->toBe(1);
});

it('does not reach across vendors when matching', function () {
    $mine    = importVendor();
    $theirs  = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    $foreign = Product::create([
        'vendor_id' => $theirs->id, 'category_id' => $category->id,
        'name' => 'Their product', 'sku' => 'SHARED-SKU', 'price' => 100,
    ]);

    runImport(csvFile("Name,SKU,Price\nMy product,SHARED-SKU,500\n"), $mine);

    expect($foreign->fresh()->name)->toBe('Their product')
        ->and(Product::where('vendor_id', $mine->id)->count())->toBe(1);
});

// ── Bad data ─────────────────────────────────────────────────────────────────

it('refuses a row with no name', function () {
    $vendor = importVendor();

    $rows = prepareImport(csvFile("Name,SKU,Price\n,SKU-1,500\nGood one,SKU-2,600\n"), $vendor);

    expect($rows->first()->errors)->toContain('Name is missing. Every product needs one.')
        ->and($rows->first()->action())->toBe(ParsedRow::ACTION_SKIP)
        ->and($rows->last()->action())->toBe(ParsedRow::ACTION_CREATE);
});

it('refuses a row with neither SKU nor barcode, since it could never be matched again', function () {
    $vendor = importVendor();

    $rows = prepareImport(csvFile("Name,SKU,Barcode,Price\nNo identifier,,,500\n"), $vendor);

    expect($rows->first()->errors)->toHaveCount(1)
        ->and($rows->first()->errors[0])->toContain('could not be matched on a later import');
});

it('refuses a negative price rather than importing it', function () {
    $vendor = importVendor();

    $rows = prepareImport(csvFile("Name,SKU,Price\nBad price,SKU-1,-500\n"), $vendor);

    expect($rows->first()->errors[0])->toBe('Price cannot be negative (got -500).');
});

it('refuses letters where a number belongs', function () {
    $vendor = importVendor();

    $rows = prepareImport(csvFile("Name,SKU,Price,Reorder Point\nBad,SKU-1,abc,many\n"), $vendor);

    expect($rows->first()->errors)->toContain('Price: "abc" is not a valid amount.')
        ->and($rows->first()->errors)->toContain('Reorder Point: "many" is not a valid whole number.');
});

it('flags the second use of a SKU inside one file and names the line it clashes with', function () {
    $vendor = importVendor();

    $rows = prepareImport(csvFile("Name,SKU,Price\nFirst,DUP,100\nSecond,DUP,200\n"), $vendor);

    expect($rows->first()->isImportable())->toBeTrue()
        ->and($rows->last()->errors[0])->toBe('SKU "DUP" is already used on line 2 of this file.');
});

it('imports the good rows and skips only the bad ones', function () {
    $vendor = importVendor();

    $log = runImport(csvFile(
        "Name,SKU,Price\nGood,SKU-1,100\n,SKU-2,200\nAlso good,SKU-3,300\nBad price,SKU-4,-1\n"
    ), $vendor);

    expect($log->created_count)->toBe(2)
        ->and($log->skipped_count)->toBe(2)
        ->and(Product::count())->toBe(2)
        // The log has to say which rows, or "2 skipped" is unactionable.
        ->and($log->errors)->toHaveCount(2)
        ->and($log->errors[0]['line'])->toBe(3);
});

it('reads prices written the way a real spreadsheet writes them', function () {
    $vendor = importVendor();

    runImport(csvFile("Name,SKU,Cost,Price\nMessy,SKU-1,\"7,500.00\",\"11,000\"\n"), $vendor);

    $product = Product::firstOrFail();

    expect((float) $product->cost_price)->toBe(7500.0)
        ->and((float) $product->price)->toBe(11000.0);
});

it('rejects a file that is not a spreadsheet', function () {
    $vendor = importVendor();

    expect(fn () => prepareImport(csvFile('not a spreadsheet', 'notes.pdf'), $vendor))
        ->toThrow(RuntimeException::class, 'Only CSV and Excel');
});

it('rejects an empty file rather than reporting a successful import of nothing', function () {
    $vendor = importVendor();

    expect(fn () => prepareImport(csvFile(''), $vendor))
        ->toThrow(RuntimeException::class, 'no rows at all');
});

it('ignores blank lines in the middle of a file', function () {
    $vendor = importVendor();

    $log = runImport(csvFile("Name,SKU,Price\nOne,SKU-1,100\n\n\nTwo,SKU-2,200\n"), $vendor);

    expect($log->created_count)->toBe(2)
        ->and($log->skipped_count)->toBe(0);
});

// ── Safety ───────────────────────────────────────────────────────────────────

it('snapshots the catalogue before an import that changes existing products', function () {
    $vendor = importVendor();

    runImport(csvFile("Name,SKU,Price\nThing,SKU-1,100\n"), $vendor);

    $log = runImport(csvFile("Name,SKU,Price\nThing,SKU-1,999\n"), $vendor);

    expect($log->hasSnapshot())->toBeTrue()
        // The snapshot must hold the old price, or it restores nothing useful.
        ->and(file_get_contents($log->snapshot_path))->toContain('100.00');
});

it('writes no snapshot for a first import, which has nothing to restore to', function () {
    $vendor = importVendor();

    $log = runImport(csvFile("Name,SKU,Price\nThing,SKU-1,100\n"), $vendor);

    expect($log->snapshot_path)->toBeNull();
});

it('leaves the catalogue untouched when the run fails partway through', function () {
    $vendor = importVendor();

    runImport(csvFile("Name,SKU,Price\nExisting,SKU-1,100\n"), $vendor);

    $rows = prepareImport(csvFile("Name,SKU,Price\nExisting,SKU-1,555\nNew one,SKU-2,200\n"), $vendor);

    // A name far longer than the column allows: the second row dies mid-run,
    // after the first has already been applied inside the transaction.
    $rows = $rows->map(fn (ParsedRow $row) => $row->line === 3
        ? new ParsedRow($row->line, [...$row->values, 'name' => str_repeat('x', 5000)], [], [], null, $row->raw)
        : $row);

    expect(fn () => app(ProductImporter::class)->commit($rows, $vendor, null, 'broken.csv'))
        ->toThrow(Throwable::class);

    // Nothing committed: the price is still the old one and no new row exists.
    expect(Product::count())->toBe(1)
        ->and((float) Product::firstOrFail()->price)->toBe(100.0)
        ->and(ImportLog::latest('id')->first()->status)->toBe('failed');
})->skip(
    fn () => DB::connection()->getDriverName() === 'sqlite',
    'SQLite does not enforce string length, so nothing fails mid-run',
);

it('logs who imported what', function () {
    $vendor = importVendor();
    $user   = User::factory()->create();

    $rows = prepareImport(csvFile("Name,SKU,Price\nThing,SKU-1,100\n"), $vendor);
    $log  = app(ProductImporter::class)->commit($rows, $vendor, $user->id, 'aronium-export.csv');

    expect($log->user_id)->toBe($user->id)
        ->and($log->vendor_id)->toBe($vendor->id)
        ->and($log->file_name)->toBe('aronium-export.csv')
        ->and($log->summary())->toBe('1 new, 0 updated, 0 skipped');
});

// ── Volume ───────────────────────────────────────────────────────────────────

it('handles a file far larger than any vendor is likely to bring', function () {
    $vendor = importVendor();

    $rows = [];

    for ($i = 1; $i <= 1000; $i++) {
        $rows[] = "Product {$i},SKU-{$i},".(1000 + $i);
    }

    $log = runImport(csvFile("Name,SKU,Price\n".implode("\n", $rows)."\n"), $vendor);

    expect($log->created_count)->toBe(1000)
        ->and($log->skipped_count)->toBe(0)
        ->and(Product::count())->toBe(1000);
});

// ── Export, and the round trip ───────────────────────────────────────────────

it('exports every field an import can read back', function () {
    $vendor = importVendor();

    runImport(csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,7500,46.6,11000,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
    )), $vendor);

    // Read back rather than string-matched: openspout writes a UTF-8 BOM and
    // quotes any field containing a space, so raw matching would be asserting
    // against CSV formatting rather than against the data.
    $path   = app(ProductExporter::class)->export($vendor);
    $record = iterator_to_array(app(SpreadsheetReader::class)->records($path))[2];

    expect($record)->toMatchArray([
        'Name'                => 'Anker 20W Charger',
        'SKU'                 => 'ANK-20W',
        'Barcode'             => '6009880111',
        'Category'            => 'Chargers',
        'Unit'                => 'pcs',
        'Supplier'            => 'Lagos Electronics',
        'Reorder Point'       => '10',
        'Preferred Quantity'  => '50',
        'Low Stock Threshold' => '5',
        'Is Service'          => 'No',
        'Status'              => 'published',
        // Written for the vendor to read, never read back in.
        'Quantity'            => '0',
    ]);
});

it('survives a full export, edit and re-import without losing anything', function () {
    $vendor = importVendor();

    runImport(csvFile(aroniumCsv(
        'Anker 20W Charger,Chargers,ANK-20W,6009880111,pcs,7500,46.6,11000,0,False,True,False,False,True,Fast charger,14,Lagos Electronics,10,50,True,5',
    )), $vendor);

    $before = Product::firstOrFail()->only([
        'name', 'sku', 'barcode', 'measurement_unit', 'brand', 'description',
        'reorder_point', 'preferred_quantity', 'low_stock_threshold',
        'is_service', 'status', 'show_online', 'show_in_pos', 'category_id', 'supplier_id',
    ]);

    // Straight back in, unedited.
    $exported = app(ProductExporter::class)->export($vendor);
    $log      = runImport($exported, $vendor);

    expect($log->updated_count)->toBe(1)
        ->and($log->created_count)->toBe(0)
        ->and(Product::count())->toBe(1)
        ->and(Product::firstOrFail()->only(array_keys($before)))->toBe($before);
});

it('exports cost as blank rather than zero when nobody has entered one', function () {
    $vendor   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'No cost', 'sku' => 'NC-1', 'price' => 1000, 'cost_price' => null,
    ]);

    $path   = app(ProductExporter::class)->export($vendor);
    $record = iterator_to_array(app(SpreadsheetReader::class)->records($path))[2];

    // "0" would be a claim about the margin. Blank is the truth.
    expect($record['Cost'])->toBe('')
        ->and($record['Price'])->toBe('1000.00');
});

it('exports only what the filters asked for', function () {
    $vendor   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);
    $other    = Category::create(['name' => 'Chargers', 'slug' => 'chargers']);

    Product::create(['vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Cable', 'sku' => 'C-1', 'price' => 100]);
    Product::create(['vendor_id' => $vendor->id, 'category_id' => $other->id, 'name' => 'Charger', 'sku' => 'C-2', 'price' => 100]);

    $path    = app(ProductExporter::class)->export($vendor, 'csv', ['category_id' => $category->id]);
    $records = iterator_to_array(app(SpreadsheetReader::class)->records($path));

    expect($records)->toHaveCount(1)
        ->and(reset($records)['Name'])->toBe('Cable');
});

it('never exports another vendor catalogue', function () {
    $mine     = importVendor();
    $theirs   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    Product::create(['vendor_id' => $mine->id, 'category_id' => $category->id, 'name' => 'Mine', 'sku' => 'M-1', 'price' => 100]);
    Product::create(['vendor_id' => $theirs->id, 'category_id' => $category->id, 'name' => 'Theirs', 'sku' => 'T-1', 'price' => 100]);

    $path    = app(ProductExporter::class)->export($mine);
    $records = iterator_to_array(app(SpreadsheetReader::class)->records($path));

    expect($records)->toHaveCount(1)
        ->and(reset($records)['Name'])->toBe('Mine');
});

it('offers a blank template that imports cleanly as one product', function () {
    $vendor = importVendor();

    $template = app(ProductExporter::class)->template();

    $log = runImport($template, $vendor);

    expect($log->created_count)->toBe(1)
        ->and($log->skipped_count)->toBe(0)
        ->and(Product::firstOrFail()->name)->toBe('Anker 20W USB-C Charger')
        // The example leaves Quantity blank so nobody reads it as importable.
        ->and(Product::firstOrFail()->stock_quantity)->toBe(0);
});

it('writes an xlsx export that reads back with the same headers', function () {
    $vendor   = importVendor();
    $category = Category::create(['name' => 'Cables', 'slug' => 'cables']);

    Product::create(['vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Cable', 'sku' => 'C-1', 'price' => 100]);

    $path = app(ProductExporter::class)->export($vendor, 'xlsx');

    expect(app(SpreadsheetReader::class)->headers($path))->toContain('Name', 'SKU', 'Cost', 'Price', 'Quantity');
});
