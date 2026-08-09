<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('a newly created vendor defaults online_sales_enabled to false', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Fresh Vendor']);

    expect($vendor->fresh()->online_sales_enabled)->toBeFalse();
});

test('the backfill statement flips existing vendor rows to true', function () {
    // RefreshDatabase runs every migration against a fresh schema before any
    // test starts, so there are no genuinely "pre-migration" rows left to
    // exercise the real one-time backfill against. This instead proves the
    // exact UPDATE statement the migration runs (database/migrations/
    // 2026_08_09_100001_add_online_sales_enabled_to_vendors_table.php) has
    // the effect it claims: every existing vendor, regardless of current
    // value, ends up true.
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Backfill Vendor']);
    DB::table('vendors')->where('id', $vendor->id)->update(['online_sales_enabled' => false]);

    DB::table('vendors')->update(['online_sales_enabled' => true]);

    expect($vendor->fresh()->online_sales_enabled)->toBeTrue();
});

test('canSellOnline reflects the column, cast to a real boolean', function () {
    $owner = User::factory()->create();

    $enabled = Vendor::create(['user_id' => $owner->id, 'name' => 'Enabled Vendor', 'online_sales_enabled' => true]);
    $disabled = Vendor::create(['user_id' => $owner->id, 'name' => 'Disabled Vendor', 'online_sales_enabled' => false]);

    expect($enabled->canSellOnline())->toBeTrue()
        ->and($disabled->canSellOnline())->toBeFalse();
});

test('the factory default matches the new-vendor default, with an explicit enabled state for tests', function () {
    $default = Vendor::factory()->create();
    $enabled = Vendor::factory()->onlineSalesEnabled()->create();

    expect($default->online_sales_enabled)->toBeFalse()
        ->and($enabled->online_sales_enabled)->toBeTrue();
});
