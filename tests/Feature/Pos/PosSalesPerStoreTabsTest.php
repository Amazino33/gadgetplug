<?php

use App\Filament\Vendor\Resources\PosSales\Pages\ListPosSales;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function storeTabsVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Tabs Vendor '.uniqid(),
    ]);
}

function storeTabsSale(Vendor $vendor, ?Store $store, array $over = []): PosSale
{
    $total = $over['total'] ?? 10000;

    return PosSale::create(array_merge([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $vendor->id,
        'store_id'        => $store?->id,
        'cashier_id'      => $vendor->user_id,
        'subtotal'        => $total,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => $total,
        'payment_method'  => 'cash',
        'amount_tendered' => $total,
        'change_given'    => 0,
        'status'          => 'completed',
        'completed_at'    => now(),
    ], $over));
}

function storeTabsPanel(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

describe('who gets tabs at all', function () {
    test('a vendor with one store sees no tabs, because there is nothing to compare', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        storeTabsSale($vendor, $vendor->defaultStore);

        storeTabsPanel($vendor, $oga);

        // A row of tabs that all show the same thing is worse than none, and
        // this page worked without them until now.
        expect(Livewire::test(ListPosSales::class)->instance()->getTabs())->toBe([]);
    });

    test('a vendor with two stores gets one tab each, plus All Stores', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        storeTabsPanel($vendor, $oga);

        $tabs = Livewire::test(ListPosSales::class)->instance()->getTabs();

        expect(array_keys($tabs))
            ->toContain('all')
            ->toContain('store-'.$phones->id)
            ->toContain('store-'.$vendor->defaultStore->id);
    });
});

describe('what a store tab shows', function () {
    test('it lists that branch\'s sales and not the other branch\'s', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $accessories = $vendor->defaultStore;
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        $atPhones = storeTabsSale($vendor, $phones, ['reference' => 'POS-PHONES-1']);
        $atAccessories = storeTabsSale($vendor, $accessories, ['reference' => 'POS-ACCESS-1']);

        storeTabsPanel($vendor, $oga);

        Livewire::test(ListPosSales::class)
            ->set('activeTab', 'store-'.$phones->id)
            ->loadTable()
            ->assertCanSeeTableRecords([$atPhones])
            ->assertCanNotSeeTableRecords([$atAccessories]);
    });

    test('All Stores shows both', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        $a = storeTabsSale($vendor, $vendor->defaultStore);
        $b = storeTabsSale($vendor, $phones);

        storeTabsPanel($vendor, $oga);

        Livewire::test(ListPosSales::class)
            ->set('activeTab', 'all')
            ->loadTable()
            ->assertCanSeeTableRecords([$a, $b]);
    });
});

describe('the day', function () {
    test('the page opens on today, so yesterday\'s trade is not counted in today\'s', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);

        $today = storeTabsSale($vendor, $vendor->defaultStore, ['reference' => 'POS-TODAY']);
        $yesterday = storeTabsSale($vendor, $vendor->defaultStore, [
            'reference'    => 'POS-YESTERDAY',
            'completed_at' => now()->subDay(),
        ]);

        storeTabsPanel($vendor, $oga);

        Livewire::test(ListPosSales::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$yesterday]);
    });

    test('widening the range brings the earlier day back', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);

        $today = storeTabsSale($vendor, $vendor->defaultStore);
        $yesterday = storeTabsSale($vendor, $vendor->defaultStore, ['completed_at' => now()->subDay()]);

        storeTabsPanel($vendor, $oga);

        Livewire::test(ListPosSales::class)
            ->set('tableFilters.period.from', now()->subWeek()->toDateString())
            ->set('tableFilters.period.until', now()->toDateString())
            ->loadTable()
            ->assertCanSeeTableRecords([$today, $yesterday]);
    });

    test('a sale that never got a completed_at still lands on the day it was rung', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);

        // An offline sale replayed through sync can arrive without one. Falling
        // back to created_at is what keeps it off a blank day.
        $noCompletion = storeTabsSale($vendor, $vendor->defaultStore, ['completed_at' => null]);

        storeTabsPanel($vendor, $oga);

        Livewire::test(ListPosSales::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$noCompletion]);
    });
});

describe('the takings on each tab', function () {
    test('each branch is badged with its own money, not the vendor total', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        storeTabsSale($vendor, $phones, ['total' => 900000]);
        storeTabsSale($vendor, $vendor->defaultStore, ['total' => 100000]);

        storeTabsPanel($vendor, $oga);

        $tabs = Livewire::test(ListPosSales::class)->instance()->getTabs();

        expect($tabs['store-'.$phones->id]->getBadge())->toBe('₦900K')
            ->and($tabs['store-'.$vendor->defaultStore->id]->getBadge())->toBe('₦100K')
            ->and($tabs['all']->getBadge())->toBe('₦1M');
    });

    test('a voided sale is not counted as takings', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        storeTabsSale($vendor, $phones, ['total' => 500000]);
        // Money that never stayed in the drawer.
        storeTabsSale($vendor, $phones, ['total' => 500000, 'status' => 'voided']);

        storeTabsPanel($vendor, $oga);

        $tabs = Livewire::test(ListPosSales::class)->instance()->getTabs();

        expect($tabs['store-'.$phones->id]->getBadge())->toBe('₦500K');
    });

    test('yesterday\'s takings are not badged on today', function () {
        $vendor = storeTabsVendor();
        $oga = User::find($vendor->user_id);
        $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

        storeTabsSale($vendor, $phones, ['total' => 200000]);
        storeTabsSale($vendor, $phones, ['total' => 800000, 'completed_at' => now()->subDay()]);

        storeTabsPanel($vendor, $oga);

        $tabs = Livewire::test(ListPosSales::class)->instance()->getTabs();

        expect($tabs['store-'.$phones->id]->getBadge())->toBe('₦200K');
    });
});

describe('other vendors', function () {
    test('another vendor\'s sales never reach these tabs', function () {
        $mine = storeTabsVendor();
        $oga = User::find($mine->user_id);
        $myPhones = Store::create(['vendor_id' => $mine->id, 'name' => 'My Phones']);

        $theirs = storeTabsVendor();
        storeTabsSale($theirs, $theirs->defaultStore, ['total' => 999000]);

        storeTabsSale($mine, $myPhones, ['total' => 100000]);

        storeTabsPanel($mine, $oga);

        $tabs = Livewire::test(ListPosSales::class)->instance()->getTabs();

        expect(array_keys($tabs))->not->toContain('store-'.$theirs->defaultStore->id)
            ->and($tabs['all']->getBadge())->toBe('₦100K');
    });
});
