<?php

use App\Livewire\Vendor\HomeDashboard;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Livewire\Livewire;

function setUpHomeDashboardStore(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Home Dashboard Vendor']);
    VendorRoles::seedFor($vendor);
    
    // VendorObserver automatically creates a default 'Main Store' upon creation
    // So there is one store already. Let's create a second one so the dropdown shows.
    $store1 = $vendor->stores()->first();
    $store2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch Two']);
    
    // Attach owner to both stores
    $store1->users()->attach($owner->id, ['last_active_at' => now()->subMinutes(2)]); // online
    $store2->users()->attach($owner->id, ['last_active_at' => now()->subMinutes(10)]); // offline

    // Add some products with low stock
    Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => \App\Models\Category::firstOrCreate(['name' => 'Test'])->id,
        'name' => 'Test Product',
        'sku' => 'TEST-1',
        'price' => 100,
        'stock_quantity' => 2, // below threshold
        'low_stock_threshold' => 5,
        'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'store1', 'store2');
}

test('dropdown renders All Stores first and online/offline dots per store', function () {
    $ctx = setUpHomeDashboardStore();
    $owner = $ctx['owner'];
    $store1 = $ctx['store1'];
    $store2 = $ctx['store2'];

    $this->actingAs($owner);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('vendor'));
    \Filament\Facades\Filament::bootCurrentPanel();
    \Filament\Facades\Filament::setTenant($ctx['vendor']);

    ActiveStore::set($ctx['vendor'], $owner, $store1->id);

    Livewire::test(HomeDashboard::class)
        ->assertSee('All Stores')
        ->assertSee($store1->name)
        ->assertSee($store2->name)
        ->assertSeeHtml('bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)]') // online dot for store1
        ->assertSeeHtml('bg-gray-400'); // offline dot for store2
});

test('All Stores selected returns the sum of all stores and renders Whole business label', function () {
    $ctx = setUpHomeDashboardStore();
    $owner = $ctx['owner'];

    $this->actingAs($owner);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('vendor'));
    \Filament\Facades\Filament::bootCurrentPanel();
    \Filament\Facades\Filament::setTenant($ctx['vendor']);
    
    Livewire::test(HomeDashboard::class)
        ->call('switchStore', 'all')
        ->assertSet('storeId', null)
        ->assertSee('Whole business') // the honest labeling badge
        ->assertSee('1 items running low on stock'); // combined low stock
});

test('switching back to a single store returns only that store\'s figures', function () {
    $ctx = setUpHomeDashboardStore();
    $owner = $ctx['owner'];
    $store1 = $ctx['store1'];

    $this->actingAs($owner);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('vendor'));
    \Filament\Facades\Filament::bootCurrentPanel();
    \Filament\Facades\Filament::setTenant($ctx['vendor']);

    Livewire::test(HomeDashboard::class)
        ->call('switchStore', $store1->id)
        ->assertSet('storeId', $store1->id)
        ->assertDontSee('Whole business'); // Should NOT show honest label for single store
});
