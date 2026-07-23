<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Models\AuditSession;
use App\Models\BlindCountEntry;
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

function setUpSoloVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name' => 'Solo Test Store',
        'pos_blind_count_participants' => 1,
    ]);

    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    $category = Category::create(['name' => 'Test Category']);

    $products = collect(range(1, 2))->map(fn (int $i) => Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => "Product {$i}",
        'sku' => "SKU-{$i}",
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 10,
        'status' => 'published',
        'published_at' => now(),
    ]))->values();

    return compact('owner', 'vendor', 'storekeeper', 'products');
}

// Filament's setTenant() fires an event that requires an authenticated user,
// so this must be called only after actingAs() in the test itself —
// actingAs() is bound to the Pest test closure's $this and can't be
// called from a plain top-level function.
function setFilamentTenant(Vendor $vendor): void
{
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

// buildProductOrder() shuffles products, so position 1 doesn't necessarily
// map to $data['products'][0] — always resolve the actual product at a given
// position from the session itself.
function productAtPosition(Vendor $vendor, int $position): Product
{
    $session = \App\Models\BlindCountSession::where('vendor_id', $vendor->id)->latest()->first();

    return Product::find($session->product_order[$position - 1]);
}

function countAllAndSubmit(array $counts)
{
    $component = Livewire::test(BlindCount::class)->call('startSession');

    foreach ($counts as $i => $count) {
        $component->set('count', $count);
        if ($i < count($counts) - 1) {
            $component->call('next');
        }
    }

    $component->call('submitAll');

    return $component;
}

test('solo count with exact match creates a verified audit session and no discrepancy', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    countAllAndSubmit([10, 10]);

    expect(AuditSession::where('vendor_id', $data['vendor']->id)->where('status', 'discrepancy')->count())->toBe(0)
        ->and(AuditSession::where('vendor_id', $data['vendor']->id)->where('status', 'verified')->count())->toBe(2);

    foreach ($data['products'] as $product) {
        expect($product->fresh()->stock_quantity)->toBe(10);
    }
});

test('solo count with a shortage is flagged as a discrepancy, not auto-corrected', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    countAllAndSubmit([7, 10]);

    $shortProduct = productAtPosition($data['vendor'], 1);
    $audit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $shortProduct->id)
        ->first();

    expect($audit->status)->toBe('discrepancy')
        ->and($audit->count_a)->toBe(7)
        ->and($audit->count_b)->toBeNull()
        ->and($shortProduct->fresh()->stock_quantity)->toBe(10);
});

test('solo count with an overage is also flagged as a discrepancy, not silently verified', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    countAllAndSubmit([15, 10]);

    $overProduct = productAtPosition($data['vendor'], 1);
    $audit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $overProduct->id)
        ->first();

    expect($audit->status)->toBe('discrepancy')
        ->and($audit->count_a)->toBe(15)
        ->and($overProduct->fresh()->stock_quantity)->toBe(10);
});

test('manager override resolves a solo discrepancy correctly, including overages', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    countAllAndSubmit([15, 10]);

    $overProduct = productAtPosition($data['vendor'], 1);
    $audit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $overProduct->id)
        ->first();

    $manager = $data['owner'];
    $this->actingAs($manager);
    setFilamentTenant($data['vendor']);

    Livewire::test(\App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource\Pages\ManageAuditSessions::class)
        ->callTableAction('manager_override', $audit, data: [
            'manager_override_count' => 15,
            'reason_code' => 'Data Entry Error',
        ]);

    $audit->refresh();

    expect($audit->status)->toBe('resolved_by_override')
        ->and($audit->manager_override_count)->toBe(15)
        ->and($audit->reason_code)->toBe('Data Entry Error')
        ->and($overProduct->fresh()->stock_quantity)->toBe(15);
});

test('blank entries are treated as zero on submit', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->call('next');
    $component->set('count', 3);
    $component->call('submitAll');

    $skippedProduct = productAtPosition($data['vendor'], 1);
    $countedProduct = productAtPosition($data['vendor'], 2);

    $skippedAudit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $skippedProduct->id)->first();
    $countedAudit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $countedProduct->id)->first();

    expect($skippedAudit->count_a)->toBe(0)
        ->and($skippedAudit->status)->toBe('discrepancy')
        ->and($countedAudit->count_a)->toBe(3)
        ->and($countedAudit->status)->toBe('discrepancy');
});

test('a non-participant observer cannot write count entries via direct component calls', function () {
    $data = setUpSoloVendor();

    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);
    $session = Livewire::test(BlindCount::class)->call('startSession');
    $sessionId = $session->get('sessionId');

    $observer = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $observer->assignRole('member');

    $this->actingAs($observer);
    setFilamentTenant($data['vendor']);
    // Instantiated directly (bypassing Livewire's request-simulation layer) —
    // Livewire's Testable can't reliably drive a second instance of the same
    // component class within one test process; this still exercises the real
    // isParticipant() guard on the real component.
    $page = new BlindCount();
    $page->mount();
    $page->count = 99;
    $page->next();

    expect(BlindCountEntry::where('blind_count_session_id', $sessionId)->where('user_id', $observer->id)->exists())
        ->toBeFalse();
});

test('one active session per vendor guard still holds', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    Livewire::test(BlindCount::class)->call('startSession');

    $secondKeeper = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $secondKeeper->assignRole('storekeeper');
    $this->actingAs($secondKeeper);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class);

    expect($component->get('sessionId'))->not->toBeNull();
});
