<?php

use App\Filament\Vendor\Pages\BlindCount;
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

// The counting screen is one Alpine component written inline in an HTML
// attribute — roughly 175 lines of JavaScript inside x-data="…". A single bare
// double quote anywhere in there closes the attribute early, and the browser
// renders the rest of the component as visible text: a wall of source code
// where the count screen should be, with nothing working.
//
// That happened, and the existing tests all passed while it was live, because
// they assert on component state and never look at whether the markup that
// carries it is well formed. These do.

/**
 * The rendered page for a storekeeper who can count.
 *
 * Self-contained rather than borrowing CountSessionScreenTest's fixtures: Pest
 * only sees a helper from another test file when that file happens to load
 * first, so sharing them would make this suite fail when run on its own.
 */
function countScreenHtml(): string
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create([
        'user_id'                      => $owner->id,
        'name'                         => 'Markup Guard Store',
        'pos_blind_count_participants' => 1,
    ]);
    $vendor->users()->attach($staff->id);

    VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Chargers'])->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 10,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_in_pos'    => true,
    ]);

    test()->actingAs($staff);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    // The counting console only renders once a count is under way; before that
    // the page shows the start screen and carries no component to inspect.
    return Livewire::test(BlindCount::class)
        ->call('startSession')
        ->html();
}

/**
 * The value of the counting component's x-data attribute, as the browser
 * would see it.
 *
 * Anchored on "entries:", which sits in the component's first few lines and is
 * therefore inside the attribute whether or not a stray quote truncated it —
 * the page also carries Filament's own x-data components, and matching the
 * first one found would measure the wrong thing.
 */
function countScreenXData(string $html): string
{
    $anchor = strpos($html, 'entries: {}');

    expect($anchor)->not->toBeFalse('the counting component should be on the page');

    $open = strrpos(substr($html, 0, $anchor), 'x-data="');

    expect($open)->not->toBeFalse('the counting component should be inside an x-data attribute');

    $start = $open + strlen('x-data="');

    // The browser ends the attribute at the very next double quote. If the
    // expression contains one, this is where it gets cut off — which is exactly
    // the bug being guarded against.
    $end = strpos($html, '"', $start);

    return substr($html, $start, $end - $start);
}

test('the counting component survives to the end of its own attribute', function () {
    $xData = countScreenXData(countScreenHtml());

    // Methods defined near the end of the component. If a stray quote cut the
    // attribute short, these fall outside it and land on the page as text.
    expect($xData)->toContain('jumpToBarcode')
        ->and($xData)->toContain('onKeydown');
});

test('none of the component leaks onto the page as visible text', function () {
    $html = countScreenHtml();

    $xData = countScreenXData($html);

    // Everything after the attribute closes. Source code showing up here is
    // what the storekeeper sees as a screenful of JavaScript.
    $afterAttribute = substr($html, strpos($html, $xData) + strlen($xData));

    expect($afterAttribute)->not->toContain('this.showToast')
        ->and($afterAttribute)->not->toContain('saveCurrentEntry')
        ->and($afterAttribute)->not->toContain('localStorage.setItem');
});

test('the barcode miss message carries no quote that would break the attribute', function () {
    // The specific line that broke it: a message quoting the scanned code,
    // written with \" — which is not an escape in an HTML attribute, so the
    // quote closed it and dumped the rest of the screen as text.
    $xData = countScreenXData(countScreenHtml());

    expect($xData)->toContain('No product found');
});
