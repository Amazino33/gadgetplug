<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Models\BlindCountAuthorization;
use App\Models\BlindCountSession;
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

function setUpCadenceVendor(string $frequency = 'daily', ?int $customDays = null): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'                      => $owner->id,
        'name'                         => 'Cadence Test Store',
        'pos_blind_count_participants' => 1,
        'pos_blind_count_frequency'    => $frequency,
        'pos_blind_count_custom_days'  => $customDays,
    ]);

    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    // authorizeRecount() only accepts users who belong to the store
    $vendor->users()->attach([$owner->id, $storekeeper->id]);

    $category = Category::create(['name' => 'Cadence Category']);

    collect(range(1, 2))->each(fn (int $i) => Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => "Cadence Product {$i}",
        'sku'            => "CAD-{$i}",
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 10,
        'status'         => 'published',
        'published_at'   => now(),
    ]));

    return compact('owner', 'vendor', 'storekeeper');
}

function completeACount(): void
{
    $c = Livewire::test(BlindCount::class)->call('startSession');
    $c->set('count', 10)->call('next');
    $c->set('count', 10)->call('submitAll');
}

test('a completed count blocks the same counter until the cadence elapses', function () {
    $data = setUpCadenceVendor('daily');
    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    completeACount();

    expect(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeTrue();

    // Starting again must not create a second session
    Livewire::test(BlindCount::class)->call('startSession');

    expect(BlindCountSession::where('vendor_id', $data['vendor']->id)->count())->toBe(1);
});

test('cadence set to none lets the same counter start again immediately', function () {
    $data = setUpCadenceVendor('none');
    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    completeACount();

    expect(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeFalse()
        ->and(BlindCountSession::nextCountDueFor($data['storekeeper']->id, $data['vendor']))->toBeNull();

    Livewire::test(BlindCount::class)->call('startSession');

    expect(BlindCountSession::where('vendor_id', $data['vendor']->id)->count())->toBe(2);
});

test('the cadence period comes from the vendor, not from the counter', function () {
    $data = setUpCadenceVendor('weekly');
    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    completeACount();

    $due = BlindCountSession::nextCountDueFor($data['storekeeper']->id, $data['vendor']);

    // A week out, not the day the old counter-chosen 'daily' default would give
    expect($due)->not->toBeNull()
        ->and($due->greaterThan(now()->addDays(6)))->toBeTrue();

    // And the session records the vendor's cadence, not a per-session choice
    expect(BlindCountSession::first()->frequency)->toBe('weekly');
});

test('a manager authorisation unblocks one early count and is then used up', function () {
    $data = setUpCadenceVendor('daily');

    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
    completeACount();

    expect(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeTrue();

    // Owner grants the re-count
    $this->actingAs($data['owner']);
    Filament::setTenant($data['vendor']);
    Livewire::test(BlindCount::class)->call('authorizeRecount', $data['storekeeper']->id);

    $grant = BlindCountAuthorization::where('user_id', $data['storekeeper']->id)->first();
    expect($grant)->not->toBeNull()
        ->and($grant->granted_by_id)->toBe($data['owner']->id)
        ->and($grant->used_at)->toBeNull();

    // Storekeeper is now clear to start
    $this->actingAs($data['storekeeper']);
    Filament::setTenant($data['vendor']);
    expect(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeFalse();

    Livewire::test(BlindCount::class)->call('startSession');

    expect(BlindCountSession::where('vendor_id', $data['vendor']->id)->count())->toBe(2)
        ->and($grant->fresh()->used_at)->not->toBeNull()
        // Spent — they are blocked again behind the same cadence
        ->and(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeTrue();
});

test('a counter cannot authorise their own re-count', function () {
    $data = setUpCadenceVendor('daily');
    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    completeACount();

    Livewire::test(BlindCount::class)->call('authorizeRecount', $data['storekeeper']->id);

    expect(BlindCountAuthorization::count())->toBe(0)
        ->and(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeTrue();
});

// The collusion case: two counters must not be able to clear each other, or the
// cadence is only ever one favour away from being lifted.
test('one counter cannot authorise another counter', function () {
    $data = setUpCadenceVendor('daily');

    $this->actingAs($data['storekeeper']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
    completeACount();

    $colleague = User::factory()->create();
    setPermissionsTeamId($data['vendor']->id);
    $colleague->assignRole('storekeeper');
    $data['vendor']->users()->attach($colleague->id);

    $this->actingAs($colleague);
    Filament::setTenant($data['vendor']);
    Livewire::test(BlindCount::class)->call('authorizeRecount', $data['storekeeper']->id);

    expect(BlindCountAuthorization::count())->toBe(0)
        ->and(BlindCountSession::isBlockedFor($data['storekeeper']->id, $data['vendor']))->toBeTrue();
});

test('storekeepers are not given the authorise permission by default', function () {
    $data = setUpCadenceVendor('daily');
    setPermissionsTeamId($data['vendor']->id);

    expect($data['storekeeper']->hasVendorPermission($data['vendor']->id, 'authorize_recount'))->toBeFalse()
        ->and($data['storekeeper']->hasVendorPermission($data['vendor']->id, 'perform_inventory_count'))->toBeTrue();
});
