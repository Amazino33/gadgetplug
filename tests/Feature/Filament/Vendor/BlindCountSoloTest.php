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

// Counting itself moved entirely into the browser (Alpine state + a
// localStorage draft) so a bad connection costs nothing until the very end —
// see BlindCount::finishCounting(). That means next()/previous()/undo/jump-
// to-barcode no longer exist as server methods to test: they're pure client
// interaction now, exercised by a manual smoke pass, not Pest. What stays
// server-testable — and is what actually matters for correctness — is
// finishCounting() itself: it writes every entry in one call and then runs
// exactly the same comparison/discrepancy logic submitAll() always has.

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

/** Builds the full entries payload finishCounting() expects, in session order. */
function countAllAndSubmit(array $counts)
{
    $component = Livewire::test(BlindCount::class)->call('startSession');

    $session = \App\Models\BlindCountSession::find($component->get('sessionId'));
    $entries = [];
    foreach ($session->product_order as $i => $productId) {
        $entries[$productId] = ['count' => $counts[$i] ?? 0, 'note' => null];
    }

    $component->call('finishCounting', $entries);

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

    Livewire::test(\App\Filament\Vendor\Resources\AuditSessions\Pages\ManageAuditSessions::class)
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

test('an item left at its default of zero still submits and is flagged as a real shortage', function () {
    // The browser always sends every product with a value — untouched means
    // it stayed at 0, not that it was left out. This is what "walking past
    // an item without typing anything" now means.
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    countAllAndSubmit([0, 10]);

    $untouchedProduct = productAtPosition($data['vendor'], 1);
    $audit = AuditSession::where('vendor_id', $data['vendor']->id)
        ->where('product_id', $untouchedProduct->id)->first();

    expect($audit->count_a)->toBe(0)
        ->and($audit->status)->toBe('discrepancy');
});

test('a product missing from the payload entirely blocks submission as incomplete', function () {
    // Defence against a truncated or tampered request — the browser is
    // supposed to always send every product, but the server never trusts
    // that alone. Mirrors submitAll()'s existing completeness guard.
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $session = \App\Models\BlindCountSession::find($component->get('sessionId'));
    $firstProductId = $session->product_order[0];

    // Only one of the two products is in the payload.
    $component->call('finishCounting', [$firstProductId => ['count' => 5, 'note' => null]]);

    expect(\App\Models\BlindCountSession::find($session->id)->status)->toBe('a_counting')
        ->and(AuditSession::where('vendor_id', $data['vendor']->id)->count())->toBe(0);
});

test('a note submitted with an entry is saved against it', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $session = \App\Models\BlindCountSession::find($component->get('sessionId'));

    $entries = [];
    foreach ($session->product_order as $i => $productId) {
        $entries[$productId] = $i === 0
            ? ['count' => 0, 'note' => 'Not found']
            : ['count' => 10, 'note' => null];
    }

    $component->call('finishCounting', $entries);

    $notedProduct = productAtPosition($data['vendor'], 1);
    $entry = BlindCountEntry::where('blind_count_session_id', $session->id)
        ->where('product_id', $notedProduct->id)->first();

    expect($entry->count)->toBe(0)
        ->and($entry->note)->toBe('Not found');
});

test('productsForCounting includes sku and barcode for every product, so the browser can jump without a round trip', function () {
    $data = setUpSoloVendor();
    $data['products']->first()->update(['barcode' => 'TESTBARCODE123']);

    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $payload = $component->instance()->productsForCounting();

    expect($payload)->toHaveCount(2);

    $withBarcode = collect($payload)->firstWhere('barcode', 'TESTBARCODE123');
    expect($withBarcode)->not->toBeNull()
        ->and($withBarcode['sku'])->not->toBeNull();
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
    $page->finishCounting([]);

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

// The counting screen runs full-screen with no panel nav, so exitCount() is
// the only way out. It no longer needs to save anything — every entry lives
// in the browser's local state (and a localStorage draft) until
// finishCounting() ships it all in one request, so leaving mid-count risks
// nothing server-side. Resuming exactly where you left off is now a
// same-device, client-side concern, verified by manual smoke test rather
// than here.
test('exiting the count writes nothing — the count exists only in the browser until finished', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $sessionId = $component->get('sessionId');
    $component->call('exitCount');

    expect(BlindCountEntry::where('blind_count_session_id', $sessionId)->count())->toBe(0);
    $component->assertRedirect();
});

// Cancelling exists because resetSession() keeps the session bound to whoever
// started it — it never frees the store for a different counter.
test('cancelling a session deletes it and frees the store for another counter', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $sessionId = $component->get('sessionId');

    // Entries are seeded directly, the way a finished (but not yet
    // submitted) browser draft would have written them via saveAllEntries() —
    // that method is private and only reachable through finishCounting(),
    // which would complete the session and make cancelling moot to test.
    $product = productAtPosition($data['vendor'], 1);
    BlindCountEntry::create([
        'blind_count_session_id' => $sessionId,
        'user_id' => $data['storekeeper']->id,
        'product_id' => $product->id,
        'position' => 1,
        'count' => 5,
    ]);

    expect(BlindCountEntry::where('blind_count_session_id', $sessionId)->count())->toBeGreaterThan(0);

    $component->call('cancelSession');

    expect(\App\Models\BlindCountSession::find($sessionId))->toBeNull()
        ->and(BlindCountEntry::where('blind_count_session_id', $sessionId)->count())->toBe(0)
        ->and($component->get('sessionId'))->toBeNull();

    // The store is free: a fresh session can be started straight away
    Livewire::test(BlindCount::class)->call('startSession');

    expect(\App\Models\BlindCountSession::whereIn('status', ['a_counting', 'b_counting'])->count())->toBe(1);
});

test('a completed session cannot be cancelled', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = countAllAndSubmit([10, 10]);
    $sessionId = $component->get('sessionId');

    expect(\App\Models\BlindCountSession::find($sessionId)->status)->toBe('completed');

    $component->call('cancelSession');

    // Still there — a completed count is an audit record, not disposable
    expect(\App\Models\BlindCountSession::find($sessionId))->not->toBeNull()
        ->and(AuditSession::where('vendor_id', $data['vendor']->id)->count())->toBe(2);
});

test('a non-participant cannot cancel someone else\'s session', function () {
    $data = setUpSoloVendor();

    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);
    $sessionId = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

    $observer = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $observer->assignRole('member');

    $this->actingAs($observer);
    setFilamentTenant($data['vendor']);
    // Instantiated directly for the same reason as the observer test above.
    $page = new BlindCount();
    $page->mount();
    $page->cancelSession();

    expect(\App\Models\BlindCountSession::find($sessionId))->not->toBeNull();
});

// Counter A must not be able to destroy their own submitted count while B is
// independently verifying it — that is exactly the work the dual count protects.
test('counter A cannot cancel once B is verifying', function () {
    $data = setUpSoloVendor();

    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);
    $component = Livewire::test(BlindCount::class)->call('startSession');
    $sessionId = $component->get('sessionId');

    $verifier = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $verifier->assignRole('storekeeper');

    // A has submitted; B is now counting
    \App\Models\BlindCountSession::find($sessionId)->update([
        'status'           => 'b_counting',
        'storekeeper_b_id' => $verifier->id,
        'a_submitted_at'   => now(),
    ]);

    $page = new BlindCount();
    $page->mount();

    expect($page->canCancel())->toBeFalse();

    $page->cancelSession();

    expect(\App\Models\BlindCountSession::find($sessionId))->not->toBeNull();
});

test('a manager can cancel a session they are not part of', function () {
    $data = setUpSoloVendor();

    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);
    $sessionId = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

    $manager = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $manager->assignRole('inventory_manager');

    $this->actingAs($manager);
    setFilamentTenant($data['vendor']);
    Livewire::test(BlindCount::class)->call('cancelSession');

    expect(\App\Models\BlindCountSession::find($sessionId))->toBeNull();
});
