<?php

use App\Filament\Vendor\Resources\ActivityLog\ActivityLogResource;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorActivity;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function logVendor(): Vendor
{
    (new VendorPermissionsSeeder())->run();
    $vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Log Store ' . uniqid()]);
    VendorRoles::seedFor($vendor);

    return $vendor;
}

function memberWithRole(Vendor $vendor, string $role): User
{
    $user = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$user->id]);
    setPermissionsTeamId($vendor->id);
    $user->assignRole($role);

    return $user;
}

test('creating a supplier is recorded against the vendor', function () {
    $vendor = logVendor();

    Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Ace Parts']);

    $entry = VendorActivity::where('vendor_id', $vendor->id)->latest()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->description)->toBe('Supplier created');
});

test('editing a supplier records what changed, not just that it changed', function () {
    $vendor   = logVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Ace Parts', 'phone' => '0800']);

    $supplier->update(['phone' => '0900']);

    $entry = VendorActivity::where('vendor_id', $vendor->id)
        ->where('description', 'Supplier updated')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('old')['phone'])->toBe('0800')
        ->and($entry->properties->get('attributes')['phone'])->toBe('0900');
});

test('one vendor never sees another vendor activity', function () {
    $a = logVendor();
    $b = logVendor();

    Supplier::create(['vendor_id' => $a->id, 'name' => 'A Supplier']);
    Supplier::create(['vendor_id' => $b->id, 'name' => 'B Supplier']);

    // Counting rows would be brittle — creating a vendor also seeds its default
    // store, which is itself logged. What matters is that neither vendor's feed
    // contains a subject belonging to the other.
    $aSubjects = VendorActivity::where('vendor_id', $a->id)->pluck('subject_id', 'subject_type');
    $bSuppliers = Supplier::where('vendor_id', $b->id)->pluck('id');

    expect(VendorActivity::where('vendor_id', $a->id)->count())->toBeGreaterThan(0)
        ->and(VendorActivity::where('vendor_id', $b->id)->count())->toBeGreaterThan(0);

    // No entry filed under A points at anything owned by B.
    $leaked = VendorActivity::where('vendor_id', $a->id)
        ->where('subject_type', Supplier::class)
        ->whereIn('subject_id', $bSuppliers)
        ->count();

    expect($leaked)->toBe(0);

    // And every supplier entry in A's feed really is A's.
    $aSupplierIds = VendorActivity::where('vendor_id', $a->id)
        ->where('subject_type', Supplier::class)
        ->pluck('subject_id');

    expect(Supplier::whereIn('id', $aSupplierIds)->pluck('vendor_id')->unique()->all())
        ->toBe([$a->id]);
});

test('a store admin can reach the activity log through the permission', function () {
    $vendor = logVendor();
    $admin  = memberWithRole($vendor, 'store_admin');

    $this->actingAs($admin);
    filament()->setTenant($vendor);

    expect(ActivityLogResource::canAccess())->toBeTrue();
});

test('a storekeeper without the permission cannot', function () {
    $vendor      = logVendor();
    $storekeeper = memberWithRole($vendor, 'storekeeper');

    $this->actingAs($storekeeper);
    filament()->setTenant($vendor);

    expect(ActivityLogResource::canAccess())->toBeFalse();
});

test('the owner can always reach it', function () {
    $vendor = logVendor();

    $this->actingAs($vendor->owner ?? User::find($vendor->user_id));
    filament()->setTenant($vendor);

    expect(ActivityLogResource::canAccess())->toBeTrue();
});

test('the log is never creatable or editable — it is a record, not a document', function () {
    expect(ActivityLogResource::canCreate())->toBeFalse()
        ->and(ActivityLogResource::canEdit(new VendorActivity()))->toBeFalse()
        ->and(ActivityLogResource::canDelete(new VendorActivity()))->toBeFalse();
});

test('a store-assigned member sees their own store plus vendor-wide entries, not other branches', function () {
    $vendor = logVendor();
    $mine   = Store::create(['vendor_id' => $vendor->id, 'name' => 'My Branch']);
    $theirs = Store::create(['vendor_id' => $vendor->id, 'name' => 'Other Branch']);

    $member = memberWithRole($vendor, 'store_admin');
    $member->stores()->syncWithoutDetaching([$mine->id]);

    // A vendor-wide entry with no store attached.
    Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Shared Supplier']);

    $this->actingAs($member);
    filament()->setTenant($vendor);

    $visible = ActivityLogResource::getEloquentQuery()->get();

    // Store creations carry their own store_id; the supplier carries none.
    expect($visible->pluck('store_id')->unique()->sort()->values()->all())
        ->toBe([null, $mine->id]);
});

test('the owner sees every store', function () {
    $vendor = logVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch One']);
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch Two']);

    $this->actingAs(User::find($vendor->user_id));
    filament()->setTenant($vendor);

    expect(ActivityLogResource::getEloquentQuery()->count())->toBeGreaterThanOrEqual(2);
});
