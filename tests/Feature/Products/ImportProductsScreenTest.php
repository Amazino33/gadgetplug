<?php

use App\Filament\Vendor\Pages\ImportProducts;
use App\Models\ImportMappingTemplate;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function importScreenContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Chip Gadget']);
    $vendor->users()->attach($staff->id);

    VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    return compact('owner', 'staff', 'vendor');
}

/**
 * Filament resolves the tenant against the signed-in user, so this only works
 * after actingAs - setting it first throws on a null user.
 */
function enterVendorPanelAs(User $user, Vendor $vendor): void
{
    test()->actingAs($user);

    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

function uploadedCsv(string $body, string $name = 'aronium.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

const SAMPLE_CSV = "Name,ProductGroup,SKU,Cost,Price,ReorderPoint\n"
    ."Anker 20W Charger,Chargers,ANK-20W,7500,11000,10\n"
    ."USB-C Cable 2m,Cables,USB-C-2M,1200,2000,20\n";

// ── Access ───────────────────────────────────────────────────────────────────

it('is closed to staff without the import permission', function () {
    $c = importScreenContext();

    enterVendorPanelAs($c['staff'], $c['vendor']);

    expect(ImportProducts::canAccess())->toBeFalse();
});

it('is open to staff granted it', function () {
    $c = importScreenContext();

    setPermissionsTeamId($c['vendor']->id);
    $c['staff']->givePermissionTo('import_products');

    enterVendorPanelAs($c['staff']->refresh(), $c['vendor']);

    expect(ImportProducts::canAccess())->toBeTrue();
});

// ── The flow ─────────────────────────────────────────────────────────────────

it('walks upload, mapping, preview and commit', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    $page = Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile');

    // Columns read, and the obvious ones already guessed.
    $page->assertSet('step', ImportProducts::STEP_MAP)
        ->assertSet('headers', ['Name', 'ProductGroup', 'SKU', 'Cost', 'Price', 'ReorderPoint'])
        ->assertSet('columnMap', ['name', 'category', 'sku', 'cost_price', 'price', 'reorder_point']);

    $page->call('buildPreview')
        ->assertSet('step', ImportProducts::STEP_PREVIEW)
        ->assertSet('summary.create', 2)
        ->assertSet('summary.update', 0)
        ->assertSet('summary.skip', 0);

    // Still nothing written - that is the whole point of the preview.
    expect(Product::count())->toBe(0);

    $page->call('commit')->assertSet('step', ImportProducts::STEP_DONE);

    expect(Product::count())->toBe(2)
        ->and(Product::where('sku', 'ANK-20W')->firstOrFail()->reorder_point)->toBe(10);
});

it('refuses to continue when no column is mapped to Name', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile')
        // Name set to "ignore".
        ->set('columnMap.0', null)
        ->call('buildPreview')
        ->assertSet('step', ImportProducts::STEP_MAP);

    expect(Product::count())->toBe(0);
});

it('shows the bad rows without blocking the good ones', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    $page = Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv("Name,SKU,Price\nGood,SKU-1,100\n,SKU-2,200\nBad price,SKU-3,-5\n"))
        ->call('loadFile')
        ->call('buildPreview');

    $page->assertSet('summary.create', 1)
        ->assertSet('summary.skip', 2);

    expect($page->get('problems'))->toHaveCount(2);

    $page->call('commit');

    expect(Product::count())->toBe(1);
});

it('rejects a file that is not a spreadsheet at the door', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    Livewire::test(ImportProducts::class)
        ->set('upload', UploadedFile::fake()->create('accounts.pdf', 10, 'application/pdf'))
        ->call('loadFile')
        ->assertHasErrors('upload')
        ->assertSet('step', ImportProducts::STEP_UPLOAD);
});

// ── Mapping templates ────────────────────────────────────────────────────────

it('saves a mapping and applies it to the next file', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile')
        ->set('columnMap.5', null)
        ->set('newTemplateName', 'Aronium export')
        ->call('saveTemplate');

    $template = ImportMappingTemplate::where('vendor_id', $c['vendor']->id)->firstOrFail();

    expect($template->name)->toBe('Aronium export')
        ->and($template->mapping)->toBe([
            'Name'         => 'name',
            'ProductGroup' => 'category',
            'SKU'          => 'sku',
            'Cost'         => 'cost_price',
            'Price'        => 'price',
        ]);

    // A fresh upload, mapped from the saved template rather than the guess.
    Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile')
        ->set('templateId', $template->id)
        ->call('applyTemplate')
        ->assertSet('columnMap', ['name', 'category', 'sku', 'cost_price', 'price', null]);
});

it('saving the same template name twice replaces it rather than duplicating', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    $page = Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile')
        ->set('newTemplateName', 'Aronium export')
        ->call('saveTemplate')
        ->set('columnMap.3', null)
        ->set('newTemplateName', 'Aronium export')
        ->call('saveTemplate');

    expect(ImportMappingTemplate::where('vendor_id', $c['vendor']->id)->count())->toBe(1)
        ->and(ImportMappingTemplate::firstOrFail()->mapping)->not->toHaveKey('Cost');
});

// ── The template download ────────────────────────────────────────────────────

it('offers a blank template to a vendor with nothing to export', function () {
    $c = importScreenContext();

    enterVendorPanelAs($c['owner'], $c['vendor']);

    // The file's contents are asserted in ProductImportTest, which imports the
    // template back and checks it produces exactly one product. This covers the
    // button being wired to it at all.
    Livewire::test(ImportProducts::class)
        ->call('downloadTemplate', 'csv')
        ->assertOk()
        ->assertHasNoErrors();
});

// ── Home store ───────────────────────────────────────────────────────────────

it('homes imported products in a branch, so they are visible in the products list', function () {
    $c = importScreenContext();
    Storage::fake('local');

    enterVendorPanelAs($c['owner'], $c['vendor']);

    Livewire::test(ImportProducts::class)
        ->set('upload', uploadedCsv(SAMPLE_CSV))
        ->call('loadFile')
        ->call('buildPreview')
        ->call('commit');

    $product = Product::where('sku', 'ANK-20W')->firstOrFail();

    // ProductResource filters on products.store_id, so a product without a home
    // store exists in the database and appears nowhere in the panel. Asserting
    // through the resource's own query rather than on the model is the point:
    // the model was never the thing at risk.
    expect($product->store_id)->not->toBeNull()
        ->and($product->storeStocks()->count())->toBe(1)
        ->and(
            \App\Filament\Vendor\Resources\Products\ProductResource::getEloquentQuery()
                ->whereKey($product->id)
                ->exists()
        )->toBeTrue();
});
