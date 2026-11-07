<?php

use App\Filament\Vendor\Pages\ImportProducts;
use App\Models\ImportLog;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Export\ProductExporter;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\ProductImporter;
use App\Services\Import\SpreadsheetReader;
use App\Services\VendorRoles;
use App\Support\Import\ProductField;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The import template carrying selling units: Wholesale Price, Alt Barcode and
// Unit 2–4, added after the original eighteen columns.

const ORIGINAL_18 = 'Name,SKU,Barcode,Category,Brand,Description,Unit,Cost,Price,Supplier,Reorder Point,Preferred Quantity,Low Stock Threshold,Is Service,Status,Show Online,Show In POS,Quantity';
const UNIT_COLUMNS = 'Wholesale Price,Alt Barcode,Unit 2 Name,Unit 2 Contains,Unit 2 Price,Unit 2 Wholesale Price,Unit 2 Barcode,Unit 3 Name,Unit 3 Contains,Unit 3 Price,Unit 3 Wholesale Price,Unit 3 Barcode,Unit 4 Name,Unit 4 Contains,Unit 4 Price,Unit 4 Wholesale Price,Unit 4 Barcode';

function unitsVendor(): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Egg Depot']);
}

function unitsCsv(string ...$rows): string
{
    $dir = storage_path('app/testing-imports');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.uniqid().'-units.csv';
    file_put_contents($path, implode("\n", [ORIGINAL_18.','.UNIT_COLUMNS, ...$rows])."\n");

    return $path;
}

/**
 * One row in the full 35-column layout. The original 18 come first, then the
 * unit columns, given by header name.
 */
function unitsRow(array $base, array $units = []): string
{
    $original = array_merge([
        'Name' => '', 'SKU' => '', 'Barcode' => '', 'Category' => 'Groceries', 'Brand' => '', 'Description' => '',
        'Unit' => 'pcs', 'Cost' => '', 'Price' => '', 'Supplier' => '', 'Reorder Point' => '', 'Preferred Quantity' => '',
        'Low Stock Threshold' => '', 'Is Service' => 'No', 'Status' => 'Yes', 'Show Online' => 'Yes', 'Show In POS' => 'Yes', 'Quantity' => '',
    ], $base);

    $extra = array_map(fn ($h) => $units[$h] ?? '', explode(',', UNIT_COLUMNS));

    return implode(',', [...array_values($original), ...$extra]);
}

function importUnits(string $path, Vendor $vendor): ImportLog
{
    $reader = app(SpreadsheetReader::class);
    $mapping = app(ColumnMapper::class)->guess($reader->headers($path));
    $rows = app(ImportPreparer::class)->prepare($path, $mapping, $vendor);

    return app(ProductImporter::class)->commit($rows, $vendor, null, basename($path));
}

function eggRow(array $extra = []): string
{
    return unitsRow(
        array_merge(['Name' => 'Egg', 'SKU' => 'EGG-01', 'Price' => '250', 'Quantity' => '300'], $extra['base'] ?? []),
        array_merge([
            'Unit 2 Name' => 'Crate', 'Unit 2 Contains' => '30', 'Unit 2 Price' => '5700', 'Unit 2 Barcode' => '8001',
            'Unit 3 Name' => 'Half Crate', 'Unit 3 Contains' => '15', 'Unit 3 Price' => '2850',
        ], $extra['units'] ?? []),
    );
}

it('maps every new column by its exact header, after the original eighteen', function () {
    $headers = explode(',', ORIGINAL_18.','.UNIT_COLUMNS);
    $mapping = app(ColumnMapper::class)->guess($headers);

    expect($mapping)->toHaveCount(35)
        ->and($mapping['Unit 2 Price'])->toBe('unit_2_price')
        ->and($mapping['Price'])->toBe('price')
        ->and($mapping['Wholesale Price'])->toBe('wholesale_price')
        ->and($mapping['Unit'])->toBe('measurement_unit')
        // The export carries GadgetPlug's Online Name and Meta Description too.
        ->and(collect(ProductField::exportColumns())->map->label()->all())->toBe(array_values(array_merge(
            ['Name', 'Online Name'],
            array_slice($headers, 1, 5),
            ['Meta Description'],
            array_slice($headers, 6),
        )));
});

it('imports the Egg row with its Crate and Half Crate at their own prices', function () {
    $vendor = unitsVendor();

    $log = importUnits(unitsCsv(eggRow()), $vendor);

    $egg = Product::where('sku', 'EGG-01')->firstOrFail();
    $crate = ProductUnit::where('product_id', $egg->id)->where('name', 'Crate')->firstOrFail();
    $half = ProductUnit::where('product_id', $egg->id)->where('name', 'Half Crate')->firstOrFail();

    expect($log->created_count)->toBe(1)
        ->and($log->units_created_count)->toBe(2)
        ->and($log->opening_stock_count)->toBe(1)
        ->and($egg->base_unit)->toBe('pcs')
        ->and($egg->stock_quantity)->toBe(300)
        ->and($crate->conversion_factor)->toBe(30)
        ->and((float) $crate->price)->toBe(5700.0)
        ->and($crate->is_active)->toBeTrue()
        ->and($half->conversion_factor)->toBe(15)
        ->and((float) $half->price)->toBe(2850.0)
        ->and(ProductBarcode::where('barcode', '8001')->value('product_unit_id'))->toBe($crate->id);
});

it('shows the imported units at the till, and finds the product by the crate barcode', function () {
    $vendor = unitsVendor();
    importUnits(unitsCsv(eggRow()), $vendor);

    Product::where('sku', 'EGG-01')->update(['status' => 'published']);
    Sanctum::actingAs($vendor->user);

    $row = $this->getJson('/api/pos/products?vendor_id='.$vendor->id)->assertOk()->json('0');

    expect(collect($row['units'])->pluck('price', 'name')->all())
        ->toMatchArray(['pcs' => 250.0, 'Crate' => 5700.0, 'Half Crate' => 2850.0]);

    $this->getJson('/api/pos/products/search?vendor_id='.$vendor->id.'&q=8001')
        ->assertOk()
        ->assertJsonPath('0.name', 'Egg');
});

it('saves a unit priced 0 switched off, with a warning', function () {
    $vendor = unitsVendor();

    $log = importUnits(unitsCsv(eggRow(['units' => ['Unit 3 Price' => '0']])), $vendor);

    expect(ProductUnit::where('name', 'Half Crate')->firstOrFail()->is_active)->toBeFalse()
        ->and(collect($log->warnings)->pluck('message')->implode(' '))->toContain('Half Crate) has a price of 0');
});

it('skips a unit whose Contains is blank, below 2 or not whole, and still imports the product', function () {
    $vendor = unitsVendor();

    $log = importUnits(unitsCsv(eggRow(['units' => [
        'Unit 2 Contains' => '1',
        'Unit 3 Contains' => '7.5',
        'Unit 4 Name' => 'Tray', 'Unit 4 Contains' => '', 'Unit 4 Price' => '900',
    ]])), $vendor);

    expect(Product::where('sku', 'EGG-01')->exists())->toBeTrue()
        ->and(ProductUnit::where('is_base', false)->count())->toBe(0)
        ->and(collect($log->warnings)->filter(fn ($w) => str_contains($w['message'], 'Contains must be a whole number'))->count())->toBe(3)
        ->and(collect($log->warnings)->pluck('line')->unique()->all())->toBe([2]);
});

it('skips a barcode another product already uses, and still imports the product', function () {
    $vendor = unitsVendor();
    importUnits(unitsCsv(unitsRow(['Name' => 'Milk', 'SKU' => 'MILK', 'Barcode' => '7001', 'Price' => '900'])), $vendor);

    $log = importUnits(unitsCsv(eggRow(['units' => ['Unit 2 Barcode' => '7001', 'Alt Barcode' => '7001']])), $vendor);

    expect(Product::where('sku', 'EGG-01')->exists())->toBeTrue()
        ->and(ProductBarcode::where('barcode', '7001')->sole()->product_id)->toBe(Product::where('sku', 'MILK')->value('id'))
        ->and(collect($log->warnings)->pluck('message')->implode(' '))->toContain('Barcode "7001" is already used by another product');
});

it('books a negative opening quantity whatever the shop setting', function () {
    $vendor = unitsVendor();

    expect($vendor->allow_negative_stock)->toBeFalsy();

    importUnits(unitsCsv(eggRow(['base' => ['Quantity' => '-24']])), $vendor);

    $egg = Product::where('sku', 'EGG-01')->firstOrFail();

    expect($egg->stock_quantity)->toBe(-24)
        ->and(InventoryLedger::where('product_id', $egg->id)->sole()->description)->toBe('Opening stock — import');
});

it('can be re-run on the same file without duplicating anything', function () {
    $vendor = unitsVendor();
    $path = unitsCsv(eggRow(['units' => ['Alt Barcode' => '9001']]));

    importUnits($path, $vendor);
    $second = importUnits($path, $vendor);

    expect(Product::count())->toBe(1)
        ->and(ProductUnit::count())->toBe(3)
        ->and(ProductBarcode::count())->toBe(2)
        ->and(Product::firstOrFail()->stock_quantity)->toBe(300)
        ->and(InventoryLedger::count())->toBe(1)
        ->and($second->updated_count)->toBe(1)
        ->and($second->units_created_count)->toBe(0)
        ->and($second->barcodes_added_count)->toBe(0);
});

it('updates a unit by name on re-import and never deletes units missing from the file', function () {
    $vendor = unitsVendor();
    importUnits(unitsCsv(eggRow()), $vendor);

    $log = importUnits(unitsCsv(unitsRow(
        ['Name' => 'Egg', 'SKU' => 'EGG-01', 'Price' => '260'],
        ['Unit 2 Name' => 'crate', 'Unit 2 Contains' => '30', 'Unit 2 Price' => '6000', 'Unit 2 Wholesale Price' => '5800'],
    )), $vendor);

    $crate = ProductUnit::where('name', 'crate')->firstOrFail();

    expect($log->units_updated_count)->toBe(1)
        ->and((float) $crate->price)->toBe(6000.0)
        ->and((float) $crate->wholesale_price)->toBe(5800.0)
        ->and(ProductUnit::where('name', 'Half Crate')->exists())->toBeTrue();
});

it('writes wholesale price to the product and its base unit', function () {
    $vendor = unitsVendor();

    importUnits(unitsCsv(unitsRow(['Name' => 'Rice', 'SKU' => 'RICE', 'Price' => '1500'], ['Wholesale Price' => '1350'])), $vendor);

    $rice = Product::where('sku', 'RICE')->firstOrFail();

    expect((float) $rice->wholesale_price)->toBe(1350.0)
        ->and((float) ProductUnit::where('product_id', $rice->id)->where('is_base', true)->value('wholesale_price'))->toBe(1350.0)
        ->and($rice->cost_price)->toBeNull();
});

it('reads barcodes and SKUs as text, and refuses Excel\'s scientific notation', function () {
    $vendor = unitsVendor();

    $log = importUnits(unitsCsv(
        unitsRow(['Name' => 'Juice', 'SKU' => '00123.0', 'Barcode' => '6009880123456.0', 'Price' => '700']),
        unitsRow(['Name' => 'Water', 'SKU' => 'WATER', 'Barcode' => '6.15E+12', 'Price' => '200']),
    ), $vendor);

    expect(Product::where('name', 'Juice')->firstOrFail())
        ->sku->toBe('00123')
        ->barcode->toBe('6009880123456')
        ->and(Product::where('name', 'Water')->exists())->toBeFalse()
        ->and(collect($log->errors)->firstWhere('line', 3)['errors'][0])->toContain('scientific notation');
});

it('imports an old 18-column file exactly as before', function () {
    $vendor = unitsVendor();
    $dir = storage_path('app/testing-imports');
    $path = $dir.'/'.uniqid().'-old.csv';
    file_put_contents($path, ORIGINAL_18."\n".'Anker 20W Charger,ANK-20W-01,6009880123456,Chargers,Anker,Fast charger,pcs,7500,11000,Lagos Electronics Ltd,10,50,5,No,Yes,Yes,Yes,'."\n");

    $log = importUnits($path, $vendor);
    $product = Product::firstOrFail();

    expect($log->created_count)->toBe(1)
        ->and($log->warnings)->toBe([])
        ->and((float) $product->cost_price)->toBe(7500.0)
        ->and((float) $product->price)->toBe(11000.0)
        ->and($product->stock_quantity)->toBe(0)
        ->and(ProductUnit::where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductBarcode::where('product_id', $product->id)->pluck('barcode')->all())->toBe(['6009880123456']);
});

it('exports the unit columns so export, edit and re-import round-trips', function () {
    $vendor = unitsVendor();
    importUnits(unitsCsv(eggRow(['units' => ['Alt Barcode' => '9001', 'Unit 2 Wholesale Price' => '5500']])), $vendor);

    $path = app(ProductExporter::class)->export($vendor);
    $record = iterator_to_array(app(SpreadsheetReader::class)->records($path))[2];

    expect($record)->toMatchArray([
        'Name' => 'Egg', 'Unit' => 'pcs', 'Alt Barcode' => '9001',
        'Unit 2 Name' => 'Crate', 'Unit 2 Contains' => '30', 'Unit 2 Price' => '5700.00', 'Unit 2 Wholesale Price' => '5500.00', 'Unit 2 Barcode' => '8001',
        'Unit 3 Name' => 'Half Crate', 'Unit 3 Contains' => '15', 'Unit 3 Price' => '2850.00',
        'Unit 4 Name' => '',
    ]);

    $again = importUnits($path, $vendor);

    expect($again->updated_count)->toBe(1)
        ->and($again->units_created_count)->toBe(0)
        ->and(ProductUnit::count())->toBe(3)
        ->and(ProductBarcode::count())->toBe(2);
});

it('on the import page, a row that fails is named by line and the rest of the batch still goes in, with a downloadable report', function () {
    (new VendorPermissionsSeeder)->run();
    Storage::fake('local');

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Batch Store']);
    VendorRoles::seedFor($vendor);

    // A row that cannot be saved, whatever the database.
    Product::creating(function (Product $p) {
        if ($p->name === 'Boom') {
            throw new RuntimeException('simulated failure');
        }
    });

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    $csv = ORIGINAL_18.','.UNIT_COLUMNS."\n"
        .eggRow(['units' => ['Unit 3 Price' => '0']])."\n"
        .unitsRow(['Name' => 'Boom', 'SKU' => 'BOOM', 'Price' => '100'])."\n"
        .unitsRow(['Name' => 'Rice', 'SKU' => 'RICE', 'Price' => '1500'])."\n";

    $page = Livewire::test(ImportProducts::class)
        ->set('upload', UploadedFile::fake()->createWithContent('catalogue.csv', $csv))
        ->call('loadFile')
        ->call('buildPreview')
        ->call('runImport')
        ->call('processBatch')
        ->assertSet('step', ImportProducts::STEP_DONE)
        ->assertSee('Row 3')
        ->assertSee('simulated failure');

    $log = ImportLog::latest('id')->firstOrFail();

    expect(Product::pluck('sku')->sort()->values()->all())->toBe(['EGG-01', 'RICE'])
        ->and($log->created_count)->toBe(2)
        ->and($log->skipped_count)->toBe(1)
        ->and($log->units_created_count)->toBe(2);

    $page->call('downloadReport')->assertFileDownloaded('import-report-'.$log->id.'.csv');
});
