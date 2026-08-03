<?php

use App\Filament\Vendor\Resources\InventoryLedgers\InventoryLedgerResource;
use App\Filament\Vendor\Resources\InventoryLedgers\Pages\ListInventoryLedgers;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpLedgerVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Ledger Test Store']);
    $category = Category::create(['name' => 'Ledger Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Ledger Test Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function actAsLedgerVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('vendor owner can access the stock movement resource', function () {
    $data = setUpLedgerVendor();
    actAsLedgerVendor($data);

    expect(InventoryLedgerResource::canAccess())->toBeTrue();
});

test('the stock movement list only shows ledger rows for the current tenant', function () {
    $dataA = setUpLedgerVendor();
    $dataB = setUpLedgerVendor();

    InventoryLedger::create([
        'vendor_id'        => $dataA['vendor']->id,
        'product_id'       => $dataA['product']->id,
        'transaction_type' => 'pos_sale',
        'quantity_change'  => -2,
        'reference'        => 'POS-AAA',
        'description'      => 'Sold via till A',
    ]);

    InventoryLedger::create([
        'vendor_id'        => $dataB['vendor']->id,
        'product_id'       => $dataB['product']->id,
        'transaction_type' => 'pos_sale',
        'quantity_change'  => -3,
        'reference'        => 'POS-BBB',
        'description'      => 'Sold via till B',
    ]);

    actAsLedgerVendor($dataA);

    Livewire::test(ListInventoryLedgers::class)
        ->assertSee('POS-AAA')
        ->assertDontSee('POS-BBB');
});

test('the stock movement list shows quantity changes with the correct sign', function () {
    $data = setUpLedgerVendor();

    InventoryLedger::create([
        'vendor_id'        => $data['vendor']->id,
        'product_id'       => $data['product']->id,
        'transaction_type' => 'restock',
        'quantity_change'  => 20,
        'reference'        => 'RESTOCK-1',
        'description'      => 'Delivery received',
    ]);

    actAsLedgerVendor($data);

    Livewire::test(ListInventoryLedgers::class)
        ->assertSee('+20')
        ->assertSee('Restock');
});

test('the stock movement resource is read-only', function () {
    expect(InventoryLedgerResource::canCreate())->toBeFalse()
        ->and(InventoryLedgerResource::canEdit(new InventoryLedger()))->toBeFalse()
        ->and(InventoryLedgerResource::canDelete(new InventoryLedger()))->toBeFalse();
});
