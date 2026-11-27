<?php

use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The admin Vendors screen: every shop readable on one screen, and its
 * switches one click away.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($this->admin);

    $this->owner  = User::factory()->create(['name' => 'Ada Okafor', 'email' => 'ada@shop.test']);
    $this->vendor = Vendor::create([
        'user_id'            => $this->owner->id,
        'name'               => 'Ada Supermarket',
        'stock_take_enabled' => true,
        // GadgetPlug starts every shop with it on; these tests switch it.
        'inventory_count_enabled' => false,
    ]);
    $this->other = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Zed Phones',
        'inventory_count_enabled' => false,
    ]);
});

test('the list shows each shop with its address name, owner, status and what is on', function () {
    Livewire::test(ListVendors::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->vendor, $this->other])
        ->assertSee('Ada Supermarket')
        ->assertSee($this->vendor->slug)
        ->assertSee('ada@shop.test')
        ->assertSee('Active')
        ->assertSee('Stock Take');
});

test('search finds a shop by its owner\'s email', function () {
    Livewire::test(ListVendors::class)
        ->searchTable('ada@shop')
        ->assertCanSeeTableRecords([$this->vendor])
        ->assertCanNotSeeTableRecords([$this->other]);
});

test('a filter lists the shops with a feature on', function () {
    Livewire::test(ListVendors::class)
        ->filterTable('stock_take_enabled', true)
        ->assertCanSeeTableRecords([$this->vendor])
        ->assertCanNotSeeTableRecords([$this->other]);
});

test('Features shows every switch as it stands and saves them for that shop alone', function () {
    Livewire::test(ListVendors::class)
        ->mountTableAction('features', $this->vendor)
        ->assertTableActionDataSet([
            'stock_take_enabled'      => true,
            'inventory_count_enabled' => false,
        ])
        ->setTableActionData([
            'stock_take_enabled'      => false,
            'inventory_count_enabled' => true,
            'online_sales_enabled'    => true,
            'owner_can_manage_roles'  => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $vendor = $this->vendor->fresh();

    expect($vendor->stock_take_enabled)->toBeFalse()
        ->and($vendor->inventory_count_enabled)->toBeTrue()
        ->and($vendor->online_sales_enabled)->toBeTrue()
        ->and((bool) $vendor->owner_can_manage_roles)->toBeTrue()
        ->and($this->other->fresh()->inventory_count_enabled)->toBeFalse();
});

test('one feature can be switched for several shops at once', function () {
    Livewire::test(ListVendors::class)
        ->callTableBulkAction('set_feature', [$this->vendor, $this->other], ['feature' => 'inventory_count_enabled', 'on' => '1'])
        ->assertHasNoTableBulkActionErrors();

    expect($this->vendor->fresh()->inventory_count_enabled)->toBeTrue()
        ->and($this->other->fresh()->inventory_count_enabled)->toBeTrue();

    Livewire::test(ListVendors::class)
        ->callTableBulkAction('set_feature', [$this->vendor], ['feature' => 'stock_take_enabled', 'on' => '0']);

    expect($this->vendor->fresh()->stock_take_enabled)->toBeFalse();
});

test('the bulk switch touches only the feature switches', function () {
    Livewire::test(ListVendors::class)
        ->callTableBulkAction('set_feature', [$this->vendor], ['feature' => 'dashboard_blocked', 'on' => '1']);

    expect($this->vendor->fresh()->dashboard_blocked)->toBeFalse();
});

test('the edit form shows the shop in sections and saves its features', function () {
    Livewire::test(EditVendor::class, ['record' => $this->vendor->getRouteKey()])
        ->assertOk()
        ->assertSee('Access')
        ->assertSee('Features')
        ->assertFormSet(['stock_take_enabled' => true])
        ->fillForm(['stock_take_enabled' => false, 'inventory_count_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->vendor->fresh()->stock_take_enabled)->toBeFalse()
        ->and($this->vendor->fresh()->inventory_count_enabled)->toBeTrue();
});

test('Block access and online sales are still one click away, under More', function () {
    Livewire::test(ListVendors::class)
        ->callTableAction('toggleOnlineSales', $this->vendor);

    expect($this->vendor->fresh()->online_sales_enabled)->toBeTrue();
});
