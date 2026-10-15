<?php

use App\Filament\Vendor\Pages\ChangePrice;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// Change Price for a product sold in more than one unit: the base unit (a
// bottle) as it always worked, and now any other unit (a crate) too.

function changePriceShop(bool $halves = false): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'               => $owner->id,
        'name'                  => 'Change Price Shop '.uniqid(),
        'allow_half_quantities' => $halves,
    ]);

    $product = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::create(['name' => 'Drinks '.uniqid()])->id,
        'name'        => 'Coke 50cl',
        'base_unit'   => 'Bottle',
        'price'       => 450,
        'cost_price'  => 350,
        'status'      => 'published',
    ]);

    $crate = ProductUnit::create([
        'product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 24,
        'price' => 9700, 'is_active' => true,
    ]);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('vendor', 'product', 'crate');
}

it('still changes the base price exactly as before', function () {
    $ctx = changePriceShop();

    Livewire::test(ChangePrice::class)
        ->call('selectProduct', $ctx['product']->id)
        ->assertSet('newPrice', 450.0)
        ->set('newPrice', 500)
        ->call('savePrice')
        ->assertSet('isSuccess', true);

    expect((float) $ctx['product']->fresh()->price)->toBe(500.0)
        ->and((float) $ctx['crate']->fresh()->price)->toBe(9700.0);
});

it('offers the other units and changes the crate price on its own', function () {
    $ctx = changePriceShop();

    Livewire::test(ChangePrice::class)
        ->call('selectProduct', $ctx['product']->id)
        ->assertSee('Crate')
        ->call('selectUnit', $ctx['crate']->id)
        ->assertSet('newPrice', 9700.0)
        ->set('newPrice', 10200)
        ->call('savePrice')
        ->assertSet('isSuccess', true);

    expect((float) $ctx['crate']->fresh()->price)->toBe(10200.0)
        ->and((float) $ctx['product']->fresh()->price)->toBe(450.0);

    // Written to the product's history like a base price change.
    expect(Activity::where('subject_id', $ctx['product']->id)->where('description', 'like', 'Crate price changed%')->exists())->toBeTrue();
});

it('warns below cost using the crate cost, not the bottle cost', function () {
    $ctx = changePriceShop();

    // A crate costs 24 × ₦350 = ₦8,400. ₦8,000 is above a bottle's cost but
    // below the crate's, so it must ask first.
    Livewire::test(ChangePrice::class)
        ->call('selectProduct', $ctx['product']->id)
        ->call('selectUnit', $ctx['crate']->id)
        ->set('newPrice', 8000)
        ->call('savePrice')
        ->assertSet('requiresConfirmation', true)
        ->assertSet('isSuccess', false);

    expect((float) $ctx['crate']->fresh()->price)->toBe(9700.0);
});

it('sets the crate half price for a shop that sells halves', function () {
    $ctx = changePriceShop(halves: true);

    Livewire::test(ChangePrice::class)
        ->call('selectProduct', $ctx['product']->id)
        ->call('selectUnit', $ctx['crate']->id)
        ->set('newHalfPrice', 5000)
        ->call('savePrice')
        ->assertSet('isSuccess', true);

    expect($ctx['crate']->fresh()->half_price)->toBe('5000.00');
});

it('never touches a half price for a shop without halves', function () {
    $ctx = changePriceShop(halves: false);
    $ctx['crate']->update(['half_price' => 5000]);

    Livewire::test(ChangePrice::class)
        ->call('selectProduct', $ctx['product']->id)
        ->call('selectUnit', $ctx['crate']->id)
        ->assertDontSee('Half price')
        ->set('newHalfPrice', null)
        ->set('newPrice', 9900)
        ->call('savePrice');

    expect($ctx['crate']->fresh()->half_price)->toBe('5000.00')
        ->and((float) $ctx['crate']->fresh()->price)->toBe(9900.0);
});
