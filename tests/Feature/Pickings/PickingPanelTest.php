<?php

use App\Actions\Pickings\ReleaseToPickerAction;
use App\Actions\Pickings\WriteOffPickingAction;
use App\Filament\Vendor\Resources\Pickers\PickerResource;
use App\Filament\Vendor\Resources\Pickers\Pages\ListPickers;
use App\Models\PickingLedgerEntry;
use App\Models\Store;
use App\Models\User;
use App\Policies\PickingWriteOffPolicy;
use App\Services\Pickings\PickingLedger;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

// Roles only receive permissions that already exist, so the permission list has
// to be seeded before any role is cut from it.
beforeEach(fn () => (new VendorPermissionsSeeder())->run());

function pickerPanel(\App\Models\Vendor $vendor, ?User $as = null): void
{
    test()->actingAs($as ?? User::find($vendor->user_id));
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

test('the owner sees every picker holding goods, worth what they are worth today', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 50, price: 1000);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Musa Bala'), $store, [['product_id' => $product->id, 'quantity' => 3]],
    );

    pickerPanel($vendor);

    Livewire::test(ListPickers::class)
        ->assertOk()
        ->assertSee('Musa Bala')
        ->assertSee('3,000.00');
});

test('a storekeeper can reach pickings, because they hand the goods out', function () {
    $vendor = pickingVendor();
    VendorRoles::seedFor($vendor);

    $keeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($vendor->id);
    $keeper->assignRole('storekeeper');

    pickerPanel($vendor, $keeper);

    expect(PickerResource::canAccess())->toBeTrue();
});

test('a plain member cannot reach pickings at all', function () {
    $vendor = pickingVendor();
    VendorRoles::seedFor($vendor);

    $member = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$member->id]);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('member');

    pickerPanel($vendor, $member);

    expect(PickerResource::canAccess())->toBeFalse();
});

test('a storekeeper sees only the branch they are standing in', function () {
    $vendor = pickingVendor();
    VendorRoles::seedFor($vendor);
    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $mainProduct = pickingProduct($vendor, $main, 20);
    $branchProduct = pickingProduct($vendor, $branch, 20);

    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Main Trader'), $main, [['product_id' => $mainProduct->id, 'quantity' => 2]],
    );
    app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor, 'Branch Trader'), $branch, [['product_id' => $branchProduct->id, 'quantity' => 4]],
    );

    $keeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$keeper->id]);
    $branch->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($vendor->id);
    $keeper->assignRole('storekeeper');

    pickerPanel($vendor, $keeper);
    \App\Services\ActiveStore::set($vendor, $keeper, $branch);

    // Both pickers are listed — they belong to the vendor — but the units and
    // value shown are only what went out from this branch.
    $rows = PickerResource::getEloquentQuery()->get()->keyBy('name');

    expect((int) $rows['Branch Trader']->units_held)->toBe(4)
        ->and((int) $rows['Main Trader']->units_held)->toBe(0);
});

test('only the owner may write off, whoever released the goods', function () {
    $vendor = pickingVendor();
    VendorRoles::seedFor($vendor);
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    $keeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($vendor->id);
    $keeper->assignRole('storekeeper');

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 2]],
        userId: $keeper->id,
    );
    $item = $picking->items->first();

    $policy = app(PickingWriteOffPolicy::class);

    expect($policy->writeOff($keeper, $item))->toBeFalse()
        ->and($policy->writeOff(User::find($vendor->user_id), $item))->toBeTrue();
});

test('the owner may write off goods they released themselves', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);
    $owner = User::find($vendor->user_id);

    // The debt policy blocks whoever granted the credit from forgiving it. Here
    // that would deadlock: the owner is the only one who may write off, and in
    // this shop the owner is often the one handing goods out.
    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 2]],
        userId: $owner->id,
    );

    expect(app(PickingWriteOffPolicy::class)->writeOff($owner, $picking->items->first()))->toBeTrue();
});

test('a write-off gives up the money without putting anything back on the shelf', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10, price: 1000);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 3]],
    );
    $item = $picking->items->first();

    app(WriteOffPickingAction::class)->execute($item, 3, userId: $vendor->user_id, note: 'Man travelled');

    $entry = PickingLedgerEntry::where('picking_item_id', $item->id)
        ->where('direction', PickingLedgerEntry::DIRECTION_WRITEOFF)
        ->first();

    expect(PickingLedger::heldQuantity($item->fresh()))->toBe(0)
        // The units never come back — they left the shelf when they were picked.
        ->and(shelfQuantity($product, $store))->toBe(7)
        ->and((float) $entry->amount)->toBe(3000.0);
});

test('nothing can be written off once the line is settled', function () {
    $vendor = pickingVendor();
    $store = $vendor->defaultStore;
    $product = pickingProduct($vendor, $store, 10);

    $picking = app(ReleaseToPickerAction::class)->execute(
        pickingPicker($vendor), $store, [['product_id' => $product->id, 'quantity' => 1]],
    );
    $item = $picking->items->first();

    app(WriteOffPickingAction::class)->execute($item, 1, userId: $vendor->user_id);

    expect(app(PickingWriteOffPolicy::class)->writeOff(User::find($vendor->user_id), $item->fresh()))->toBeFalse()
        ->and(fn () => app(WriteOffPickingAction::class)->execute($item->fresh(), 1))
            ->toThrow(RuntimeException::class, 'Nothing is still out');
});
