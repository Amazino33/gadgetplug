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

test('previous navigates back and preserves the entered count on both items', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->set('count', 5);
    $component->call('next');
    $component->set('count', 3);
    $component->call('previous');

    // Back on item 1 — its previously entered count must still be there
    expect($component->get('count'))->toBe(5)
        ->and($component->get('currentPosition'))->toBe(1);

    $firstProduct = productAtPosition($data['vendor'], 1);
    $secondProduct = productAtPosition($data['vendor'], 2);

    expect(BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $firstProduct->id)->first()->count)->toBe(5)
        ->and(BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $secondProduct->id)->first()->count)->toBe(3);
});

test('previous is a no-op on the first item', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->call('previous');

    expect($component->get('currentPosition'))->toBe(1);
});

test('mark not found saves a zero count with a note and advances', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->call('markNotFound');

    $firstProduct = productAtPosition($data['vendor'], 1);
    $entry = BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $firstProduct->id)->first();

    expect($entry->count)->toBe(0)
        ->and($entry->note)->toBe('Not found')
        ->and($component->get('currentPosition'))->toBe(2);
});

test('a note persists across navigation', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->set('count', 2);
    $component->set('note', 'Box was damaged');
    $component->call('next');
    $component->call('previous');

    expect($component->get('note'))->toBe('Box was damaged');

    $firstProduct = productAtPosition($data['vendor'], 1);
    $entry = BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $firstProduct->id)->first();

    expect($entry->note)->toBe('Box was damaged');
});

test('undo last reverts the most recently saved entry to its prior value', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->set('count', 5);
    $component->call('next'); // saves item 1 = 5 (previous value was uncounted/null)

    $component->call('undoLast');

    $firstProduct = productAtPosition($data['vendor'], 1);
    $entry = BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $firstProduct->id)->first();

    expect($entry->count)->toBeNull()
        ->and($component->get('currentPosition'))->toBe(1)
        ->and($component->get('count'))->toBe(0)
        ->and($component->get('canUndo'))->toBeFalse();
});

test('jump to barcode navigates to the matching product in this session', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');

    $secondProduct = productAtPosition($data['vendor'], 2);
    $secondProduct->update(['barcode' => 'TESTBARCODE123']);

    $component->call('jumpToBarcode', 'TESTBARCODE123');

    expect($component->get('currentPosition'))->toBe(2);
});

// The counting screen runs full-screen with no panel nav, so exitCount() is the
// only way out. It must not lose the number currently on screen: that entry is
// otherwise only persisted by next()/previous().
test('exiting the count saves the entry currently on screen', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->set('count', 8);
    $component->call('exitCount');

    $firstProduct = productAtPosition($data['vendor'], 1);
    $entry = BlindCountEntry::where('blind_count_session_id', $component->get('sessionId'))
        ->where('product_id', $firstProduct->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->count)->toBe(8);

    $component->assertRedirect();
});

test('re-entering after an exit resumes at the next uncounted item, keeping the saved one', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $sessionId = Livewire::test(BlindCount::class)
        ->call('startSession')
        ->set('count', 6)
        ->call('exitCount')
        ->get('sessionId');

    // A fresh mount is what happens when the storekeeper navigates back in.
    // currentPositionFor() resumes at last-counted + 1, so item 1 is not redone.
    $resumed = Livewire::test(BlindCount::class);

    expect($resumed->get('sessionId'))->toBe($sessionId)
        ->and($resumed->get('currentPosition'))->toBe(2);

    $firstProduct = productAtPosition($data['vendor'], 1);

    expect(BlindCountEntry::where('blind_count_session_id', $sessionId)
        ->where('product_id', $firstProduct->id)
        ->first()->count)->toBe(6);
});

test('exiting as a non-participant does not write a count entry', function () {
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
    $page->count = 99;
    $page->exitCount();

    expect(BlindCountEntry::where('blind_count_session_id', $sessionId)->where('user_id', $observer->id)->exists())
        ->toBeFalse();
});

// Cancelling exists because resetSession() keeps the session bound to whoever
// started it — it never frees the store for a different counter.
test('cancelling a session deletes it and frees the store for another counter', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $sessionId = $component->get('sessionId');
    $component->set('count', 5)->call('next');

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

test('jump to barcode with no match warns and does not move', function () {
    $data = setUpSoloVendor();
    $this->actingAs($data['storekeeper']);
    setFilamentTenant($data['vendor']);

    $component = Livewire::test(BlindCount::class)->call('startSession');
    $component->call('jumpToBarcode', 'NO-SUCH-BARCODE');

    expect($component->get('currentPosition'))->toBe(1);
});
