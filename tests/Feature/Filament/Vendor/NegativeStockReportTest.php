<?php

use App\Filament\Vendor\Pages\NegativeStockReport;
use App\Filament\Vendor\Pages\StoreProfile;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function negativeReportContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Negative Store', 'allow_negative_stock' => true]);
    VendorRoles::seedFor($vendor);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Drinks'])->id,
        'name'           => 'Malt 33cl',
        'base_unit'      => 'Bottle',
        'price'          => 500,
        'cost_price'     => 400,
        'stock_quantity' => 2,
        'status'         => 'published',
        'show_in_pos'    => true,
    ]);

    return compact('owner', 'vendor', 'product');
}

test('the shop owner can turn selling below zero on and off', function () {
    $ctx = negativeReportContext();
    $ctx['vendor']->update(['allow_negative_stock' => false]);

    $this->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(StoreProfile::class)
        ->assertFormSet(['allow_negative_stock' => false])
        ->fillForm(['allow_negative_stock' => true])
        ->call('save');

    expect($ctx['vendor']->fresh()->allow_negative_stock)->toBeTrue();
});

test('a product sold below zero shows with what is owed and its estimated-cost sale', function () {
    $ctx = negativeReportContext();

    Sanctum::actingAs($ctx['owner']);
    $this->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => [['product_id' => $ctx['product']->id, 'product_name' => 'Malt 33cl', 'unit_price' => 500, 'quantity' => 5]],
        'payment_method'  => 'cash',
        'amount_tendered' => 2500,
        'vat_rate'        => 0,
    ])->assertCreated();

    // Back from the till's API guard to the panel's.
    auth()->shouldUse('web');
    $this->actingAs($ctx['owner'], 'web');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    Livewire::test(NegativeStockReport::class)
        ->assertOk()
        ->assertSee('Malt 33cl')
        ->assertSee('3 Bottle')
        ->assertSee('₦2,000.00'); // 5 × ₦400, estimated
});

test('another shop\'s negative stock is not shown', function () {
    $mine  = negativeReportContext();
    $other = negativeReportContext();

    Sanctum::actingAs($other['owner']);
    $this->postJson('/api/pos/sales', [
        'vendor_id'       => $other['vendor']->id,
        'items'           => [['product_id' => $other['product']->id, 'product_name' => 'Malt 33cl', 'unit_price' => 500, 'quantity' => 5]],
        'payment_method'  => 'cash',
        'amount_tendered' => 2500,
        'vat_rate'        => 0,
    ])->assertCreated();

    auth()->shouldUse('web');
    $this->actingAs($mine['owner'], 'web');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($mine['vendor']);

    Livewire::test(NegativeStockReport::class)
        ->assertOk()
        ->assertSee('Nothing is below zero.');
});
