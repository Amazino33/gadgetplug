<?php

use App\Filament\Vendor\Resources\PosSales\PosSaleResource;
use App\Filament\Vendor\Resources\PosSales\Pages\ListPosSales;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpPosSalesVendor(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'POS Sales Vendor Store']);
    $category = Category::create(['name' => 'POS Sales Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'POS Sales Product',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function actAsPosSalesVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

function makePosSaleRecord(Vendor $vendor, Product $product, User $cashier, string $reference): PosSale
{
    $sale = PosSale::create([
        'reference'       => $reference,
        'vendor_id'       => $vendor->id,
        'cashier_id'      => $cashier->id,
        'subtotal'        => 5000,
        'vat_amount'      => 0,
        'total'           => 5000,
        'payment_method'  => 'cash',
        'amount_tendered' => 5000,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'unit_price'   => 5000,
        'quantity'     => 1,
        'total'        => 5000,
    ]);

    return $sale;
}

test('the vendor owner can access the POS sales resource', function () {
    $data = setUpPosSalesVendor();
    actAsPosSalesVendor($data);

    expect(PosSaleResource::canAccess())->toBeTrue();
});

test('the vendor sees every cashier\'s sales, not just their own', function () {
    $data     = setUpPosSalesVendor();
    $cashierA = User::factory()->create();
    $cashierB = User::factory()->create();

    makePosSaleRecord($data['vendor'], $data['product'], $cashierA, 'POS-CASHIER-A');
    makePosSaleRecord($data['vendor'], $data['product'], $cashierB, 'POS-CASHIER-B');

    actAsPosSalesVendor($data);

    Livewire::test(ListPosSales::class)
        ->assertSee('POS-CASHIER-A')
        ->assertSee('POS-CASHIER-B');
});

test('the POS sales list only shows sales for the current tenant', function () {
    $dataA = setUpPosSalesVendor();
    $dataB = setUpPosSalesVendor();

    $cashier = User::factory()->create();
    makePosSaleRecord($dataA['vendor'], $dataA['product'], $cashier, 'POS-TENANT-A');
    makePosSaleRecord($dataB['vendor'], $dataB['product'], $cashier, 'POS-TENANT-B');

    actAsPosSalesVendor($dataA);

    Livewire::test(ListPosSales::class)
        ->assertSee('POS-TENANT-A')
        ->assertDontSee('POS-TENANT-B');
});

test('the POS sales resource is read-only', function () {
    expect(PosSaleResource::canCreate())->toBeFalse()
        ->and(PosSaleResource::canEdit(new PosSale()))->toBeFalse()
        ->and(PosSaleResource::canDelete(new PosSale()))->toBeFalse();
});
