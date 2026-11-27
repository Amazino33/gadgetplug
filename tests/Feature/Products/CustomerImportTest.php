<?php

use App\Filament\Vendor\Pages\ImportCustomers;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Import\CustomerImporter;
use App\Services\Import\SpreadsheetReader;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Bringing a shop's existing customer list in, with what each one owes.

function customerVendor(): Vendor
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Veruzo Superstore']);
    VendorRoles::seedFor($vendor);

    return $vendor;
}

function customersCsv(string $body): string
{
    $dir = storage_path('app/testing-imports');
    if (! is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir.'/'.uniqid().'-customers.csv';
    file_put_contents($path, $body);

    return $path;
}

function importCustomersFile(string $path, Vendor $vendor): array
{
    $importer = app(CustomerImporter::class);
    $mapping  = $importer->guessMapping(app(SpreadsheetReader::class)->headers($path));
    $rows     = $importer->prepare($path, $mapping, $vendor);

    return ['rows' => $rows, 'counts' => $importer->applyChunk($rows, $vendor, $vendor->user_id, null)];
}

const CUSTOMERS = "Customer Name,Mobile,Email,Address,Balance Due,Memo\n"
    ."Mama Nkechi,0803 123 4567,nkechi@example.com,12 Aka Road,15000,Pays Fridays\n"
    ."Emeka Stores,+2348059876543,,Itam Market,0,\n"
    ."Walk-in Joe,,,,,\n";

it('recognises common QuickBooks-style headers', function () {
    $mapping = app(CustomerImporter::class)->guessMapping(['Customer Name', 'Mobile', 'Email', 'Address', 'Balance Due', 'Memo']);

    expect($mapping)->toBe([
        'Customer Name' => 'name', 'Mobile' => 'phone', 'Email' => 'email',
        'Address' => 'address', 'Balance Due' => 'opening_balance', 'Memo' => 'notes',
    ]);
});

it('imports customers, and records what a new customer already owes as a debt', function () {
    $vendor = customerVendor();

    ['counts' => $counts] = importCustomersFile(customersCsv(CUSTOMERS), $vendor);

    $nkechi = PosCustomer::where('name', 'Mama Nkechi')->firstOrFail();
    $entry  = PosCustomerLedgerEntry::where('pos_customer_id', $nkechi->id)->sole();

    expect($counts['created'])->toBe(3)
        ->and($counts['balances'])->toBe(1)
        ->and($nkechi->vendor_id)->toBe($vendor->id)
        ->and($nkechi->notes)->toBe('Pays Fridays')
        ->and($entry->direction)->toBe('charge')
        ->and((float) $entry->amount)->toBe(15000.0)
        ->and($entry->description)->toBe('Opening balance — import');
});

it('can be re-run without duplicating anyone or their balance', function () {
    $vendor = customerVendor();
    $path   = customersCsv(CUSTOMERS);

    importCustomersFile($path, $vendor);
    ['counts' => $again, 'rows' => $rows] = importCustomersFile($path, $vendor);

    expect(PosCustomer::count())->toBe(3)
        ->and(PosCustomerLedgerEntry::count())->toBe(1)
        ->and($again['created'])->toBe(0)
        ->and($again['updated'])->toBe(3)
        ->and($rows->firstWhere('line', 2)['warnings'][0])->toContain('opening balance was not added');
});

it('matches a phone however it is written', function () {
    $vendor = customerVendor();
    PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Nkechi (till)', 'phone' => '+2348031234567']);

    ['counts' => $counts] = importCustomersFile(customersCsv("Name,Phone\nMama Nkechi,0803-123-4567\n"), $vendor);

    expect($counts['updated'])->toBe(1)
        ->and(PosCustomer::count())->toBe(1)
        ->and(PosCustomer::firstOrFail()->name)->toBe('Mama Nkechi');
});

it('skips a row with no name, and leaves out a phone Excel has mangled', function () {
    $vendor = customerVendor();

    ['rows' => $rows] = importCustomersFile(customersCsv("Name,Phone\n,08031234567\nAda,2.34803E+12\n"), $vendor);

    expect($rows->firstWhere('line', 2)['action'])->toBe('skip')
        ->and($rows->firstWhere('line', 3)['warnings'][0])->toContain('shortened by Excel')
        ->and(PosCustomer::where('name', 'Ada')->value('phone'))->toBeNull();
});

it('never touches another shop\'s customers', function () {
    $mine  = customerVendor();
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other']);
    PosCustomer::create(['vendor_id' => $other->id, 'name' => 'Theirs', 'phone' => '08031234567']);

    importCustomersFile(customersCsv("Name,Phone\nMama Nkechi,08031234567\n"), $mine);

    expect(PosCustomer::where('vendor_id', $mine->id)->count())->toBe(1)
        ->and(PosCustomer::where('vendor_id', $other->id)->value('name'))->toBe('Theirs');
});

it('runs end to end on the page, with a downloadable report', function () {
    $vendor = customerVendor();
    Storage::fake('local');

    $this->actingAs($vendor->user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    $page = Livewire::test(ImportCustomers::class)
        ->set('upload', UploadedFile::fake()->createWithContent('customers.csv', CUSTOMERS."Nameless,,,,abc,\n,,,,,\n"))
        ->call('loadFile')
        ->assertSet('step', ImportCustomers::STEP_MAP)
        ->call('buildPreview')
        ->assertSet('step', ImportCustomers::STEP_PREVIEW)
        ->assertSet('summary.create', 3)
        ->call('runImport')
        ->call('processBatch')
        ->assertSet('step', ImportCustomers::STEP_DONE)
        ->assertSee('Row 5');

    expect(PosCustomer::count())->toBe(3);

    $page->call('downloadReport')->assertFileDownloaded('customer-import-report.csv');
});
