<?php

/**
 * Platform staff onboarding a vendor's catalogue for them.
 *
 * Vendors arrive with three hundred products already typed up somewhere else
 * and send us the spreadsheet rather than working the wizard themselves. The
 * import then has to say, permanently and in the vendor's own panel, that we
 * were the ones who ran it — otherwise the only record is a user_id pointing at
 * a name on our side that means nothing to them.
 */

use App\Filament\Vendor\Pages\ImportProducts;
use App\Filament\Vendor\Resources\ImportLogs\ImportLogResource;
use App\Filament\Vendor\Resources\ImportLogs\Pages\ListImportLogs;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const ADMIN_IMPORT_CSV = "Name,ProductGroup,SKU,Cost,Price\n"
    ."Oraimo Powerbank 20000mAh,Power,ORA-PB20,14000,21000\n"
    ."Oraimo Earbuds,Audio,ORA-EB1,9000,15000\n";

/**
 * A super admin the Spatie team scope cannot lose.
 *
 * The role row must be assigned with no team: isSuperAdmin() keys off
 * model_has_roles.team_id being NULL, and whatever team a previous line in the
 * test left set would otherwise be written into that pivot.
 */
function platformAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Tolu on support']);

    setPermissionsTeamId(null);
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin->refresh();
}

function onboardingVendor(string $name = 'Chip Gadget'): Vendor
{
    (new VendorPermissionsSeeder())->run();

    $vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => $name]);

    VendorRoles::seedFor($vendor);

    return $vendor;
}

/**
 * Filament resolves the tenant against the signed-in user, so actingAs comes
 * first.
 *
 * The panel is booted explicitly. setCurrentPanel() only assigns a property —
 * it is Panel::boot() that registers the tenancy global scope on every
 * resource's model, which a real request gets from the panel middleware and a
 * Livewire test does not. Without it a resource query returns every vendor's
 * rows and an isolation test passes while proving nothing.
 */
function enterPanelForOnboarding(User $user, Vendor $vendor): void
{
    test()->actingAs($user);

    $panel = Filament::getPanel('vendor');

    Filament::setCurrentPanel($panel);
    $panel->boot();

    Filament::setTenant($vendor);
}

/** Drives the wizard end to end on a two-row file. */
function runAdminImport(string $body = ADMIN_IMPORT_CSV): ImportLog
{
    Livewire::test(ImportProducts::class)
        ->set('upload', UploadedFile::fake()->createWithContent('onboarding.csv', $body))
        ->call('loadFile')
        ->call('buildPreview')
        ->call('runImport')
        ->call('processBatch')
        ->assertSet('step', ImportProducts::STEP_DONE);

    return ImportLog::latest('id')->firstOrFail();
}

// ── Access ───────────────────────────────────────────────────────────────────

it('lets platform staff reach the import screen for a vendor they are no part of', function () {
    $vendor = onboardingVendor();

    enterPanelForOnboarding(platformAdmin(), $vendor);

    expect(ImportProducts::canAccess())->toBeTrue();
});

it('still refuses vendor staff who were never granted the import permission', function () {
    $vendor = onboardingVendor();

    $staff = User::factory()->create();
    $vendor->users()->attach($staff->id);

    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    enterPanelForOnboarding($staff->refresh(), $vendor);

    expect(ImportProducts::canAccess())->toBeFalse();
});

/**
 * The real route, not just the gate.
 *
 * canAccess() returning true and the page actually rendering for someone who
 * belongs to no vendor are different claims: the panel's tenant middleware,
 * the store resolution and the sidebar all run in between, and each of them
 * has a membership branch that a super admin falls outside of.
 */
it('serves the import screen over HTTP to platform staff', function () {
    $vendor = onboardingVendor();

    $this->actingAs(platformAdmin())
        ->get('/plug/'.$vendor->slug.'/import-products')
        ->assertOk()
        ->assertSee('You are importing as '.config('app.name').' staff, for '.$vendor->name, false);
});

it('serves the import history over HTTP to the vendor owner', function () {
    $vendor = onboardingVendor();

    $this->actingAs(User::find($vendor->user_id))
        ->get('/plug/'.$vendor->slug.'/import-logs')
        ->assertOk();
});

// ── Attribution ──────────────────────────────────────────────────────────────

it('records an import run by platform staff as done by support', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();
    $admin  = platformAdmin();

    enterPanelForOnboarding($admin, $vendor);

    $log = runAdminImport();

    expect($log->performed_by_admin)->toBeTrue()
        // The individual stays on the row — it is the label shown to the vendor
        // that becomes the platform, not the underlying record.
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->actorLabel())->toBe(config('app.name').' support')
        ->and($log->vendor_id)->toBe($vendor->id)
        ->and($log->created_count)->toBe(2);
});

it('homes the imported products in the vendor, not in whoever ran the import', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();

    enterPanelForOnboarding(platformAdmin(), $vendor);

    runAdminImport();

    expect(Product::count())->toBe(2)
        ->and(Product::where('vendor_id', $vendor->id)->count())->toBe(2);
});

it('records the branch the import landed in', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();

    enterPanelForOnboarding(platformAdmin(), $vendor);

    $log = runAdminImport();

    // Which branch an import lands in is decided by the active store and
    // nothing in the file, so the log has to carry it or the question is
    // unanswerable afterwards.
    expect($log->store_id)->toBe(Store::where('vendor_id', $vendor->id)->value('id'))
        ->and($log->store->vendor_id)->toBe($vendor->id);
});

it('does not label the vendor doing their own import as support', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();
    $owner  = User::find($vendor->user_id);

    enterPanelForOnboarding($owner, $vendor);

    $log = runAdminImport();

    expect($log->performed_by_admin)->toBeFalse()
        ->and($log->actorLabel())->toBe($owner->name);
});

it('does not label a platform admin importing into their own shop as support', function () {
    Storage::fake('local');

    // Someone on our side who also sells on the marketplace. In their own
    // panel they are the vendor, and calling that run "support" would name the
    // wrong party as accountable for their own catalogue.
    $admin = platformAdmin();

    (new VendorPermissionsSeeder())->run();
    $vendor = Vendor::create(['user_id' => $admin->id, 'name' => 'Tolu Gadgets']);
    VendorRoles::seedFor($vendor);

    enterPanelForOnboarding($admin, $vendor);

    $log = runAdminImport();

    expect($log->performed_by_admin)->toBeFalse()
        ->and($log->actorLabel())->toBe($admin->name);
});

it('marks a command-line import as run from our end', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();
    $path   = Storage::disk('local')->path('cli-import.csv');

    Storage::disk('local')->put('cli-import.csv', ADMIN_IMPORT_CSV);

    // Reaching artisan means shell access to the server, which no vendor has.
    $this->artisan('products:import', ['file' => $path, '--vendor' => $vendor->id])
        ->assertSuccessful();

    expect(ImportLog::latest('id')->firstOrFail()->performed_by_admin)->toBeTrue();
});

// ── The vendor can see it ────────────────────────────────────────────────────

it('shows the vendor every import of their catalogue, and who ran it', function () {
    Storage::fake('local');

    $vendor = onboardingVendor();

    enterPanelForOnboarding(platformAdmin(), $vendor);
    $log = runAdminImport();

    // The owner, in their own panel, reading what we did on their behalf.
    enterPanelForOnboarding(User::find($vendor->user_id), $vendor);

    Livewire::test(ListImportLogs::class)
        ->assertCanSeeTableRecords([$log])
        ->assertSee('onboarding.csv')
        ->assertSee(config('app.name').' support');
});

it('never shows one vendor another vendor imports', function () {
    Storage::fake('local');

    $mine     = onboardingVendor('Chip Gadget');
    $theirs   = onboardingVendor('Rival Gadget');
    $admin    = platformAdmin();

    enterPanelForOnboarding($admin, $theirs);
    $theirLog = runAdminImport();

    enterPanelForOnboarding(User::find($mine->user_id), $mine);

    Livewire::test(ListImportLogs::class)
        ->assertCanNotSeeTableRecords([$theirLog]);
});

it('is closed to vendor staff who may not import', function () {
    $vendor = onboardingVendor();

    $staff = User::factory()->create();
    $vendor->users()->attach($staff->id);

    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    enterPanelForOnboarding($staff->refresh(), $vendor);

    expect(ImportLogResource::canAccess())->toBeFalse();
});
