<?php

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function badgeVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Badge Vendor '.uniqid(),
    ]);
}

function badgeProcurement(Vendor $vendor, string $status = 'pending'): Procurement
{
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Badge Supplier '.uniqid()]);

    return Procurement::create([
        'vendor_id'      => $vendor->id,
        'supplier_id'    => $supplier->id,
        'reference'      => 'PO-'.strtoupper(Str::random(8)),
        'status'         => $status,
        'created_by'     => $vendor->user_id,
        'total_cost'     => 0,
        'amount_paid'    => 0,
        'payment_status' => 'credit',
        'payment_method' => 'cash',
    ]);
}

function badgePanel(Vendor $vendor): void
{
    test()->actingAs(User::find($vendor->user_id));
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

test('nothing pending shows no badge at all', function () {
    $vendor = badgeVendor();
    badgeProcurement($vendor, 'approved');

    badgePanel($vendor);

    // A badge reading "0" is noise on the navigation for every day there is
    // nothing to do.
    expect(ProcurementResource::getNavigationBadge())->toBeNull();
});

test('pending deliveries are counted', function () {
    $vendor = badgeVendor();
    badgeProcurement($vendor);
    badgeProcurement($vendor);
    badgeProcurement($vendor, 'approved');

    badgePanel($vendor);

    expect(ProcurementResource::getNavigationBadge())->toBe('2');
});

test('another vendor\'s pending deliveries are not counted', function () {
    $mine = badgeVendor();
    $theirs = badgeVendor();

    badgeProcurement($mine);
    badgeProcurement($theirs);
    badgeProcurement($theirs);

    badgePanel($mine);

    expect(ProcurementResource::getNavigationBadge())->toBe('1');
});

test('approving one takes it off the count', function () {
    $vendor = badgeVendor();
    $order = badgeProcurement($vendor);

    badgePanel($vendor);

    expect(ProcurementResource::getNavigationBadge())->toBe('1');

    $order->update(['status' => 'approved']);

    expect(ProcurementResource::getNavigationBadge())->toBeNull();
});

test('a voided order is not waiting for anybody', function () {
    $vendor = badgeVendor();
    badgeProcurement($vendor, 'voided');

    badgePanel($vendor);

    expect(ProcurementResource::getNavigationBadge())->toBeNull();
});
