<?php

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Products\SkuGenerator;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function skuShop(string $name = 'Sku Shop', array $vendorAttributes = []): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(array_merge(['user_id' => $owner->id, 'name' => $name.' '.uniqid()], $vendorAttributes));
    VendorRoles::seedFor($vendor);

    return [$owner, $vendor];
}

function skuPanel(User $owner, Vendor $vendor): void
{
    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

function skuProduct(Vendor $vendor, array $attributes = []): Product
{
    return Product::create(array_merge([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::firstOrCreate(['name' => 'Alcohol'])->id,
        'name'        => 'Castile lite',
        'price'       => 10100,
        'status'      => 'published',
    ], $attributes));
}

// ── Generation ──────────────────────────────────────────────────────────────

test('a product created without a SKU is given one in the category-name-number shape', function () {
    [, $vendor] = skuShop();

    $product = skuProduct($vendor);

    expect($product->sku)->toMatch('/^ALC-CASTLI-\d{4}$/');
});

test('the category falls back to GEN and the name to its first four letters of each word', function () {
    $generator = app(SkuGenerator::class);

    expect($generator->categoryCode(null))->toBe('GEN')
        ->and($generator->nameCode('Golden Penny Semovita'))->toBe('GOLDPE')
        ->and($generator->nameCode('Coke'))->toBe('COKE')
        ->and($generator->nameCode('!!!'))->toBe('ITEM');
});

test('two products with the same name in a shop get different SKUs', function () {
    [, $vendor] = skuShop();

    $first  = skuProduct($vendor);
    $second = skuProduct($vendor);

    expect($first->sku)->not->toBe($second->sku);
});

test('a SKU the generator picks is found free even when the count would have clashed', function () {
    [, $vendor] = skuShop();

    // Two products, then one deleted: count + 1 would be the number now taken.
    $a = skuProduct($vendor);
    $b = skuProduct($vendor);
    $a->delete();

    $c = skuProduct($vendor);

    expect($c->sku)->not->toBe($b->sku);
});

test('a SKU is unique per shop, not across shops', function () {
    [, $one] = skuShop('One');
    [, $two] = skuShop('Two');

    skuProduct($one, ['sku' => 'SHARED-1']);
    $other = skuProduct($two, ['sku' => 'SHARED-1']);

    expect($other->sku)->toBe('SHARED-1');

    expect(fn () => skuProduct($one, ['name' => 'Another', 'sku' => 'SHARED-1']))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('a product saved with its SKU blanked gets a fresh one rather than null', function () {
    [, $vendor] = skuShop();
    $product = skuProduct($vendor);

    $product->update(['sku' => '']);

    expect($product->fresh()->sku)->toMatch('/^ALC-CASTLI-\d{4}$/');
});

// ── The form ────────────────────────────────────────────────────────────────

test('creating a product from the form saves a generated SKU, not the preview', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    $category = Category::create(['name' => 'Alcohol']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Castile lite', 'category_id' => $category->id, 'price' => 10100, 'status' => 'draft', 'sku' => 'PREVIEW-0001'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('name', 'Castile lite')->value('sku'))->toMatch('/^ALC-CASTLI-\d{4}$/');
});

test('a SKU typed by the vendor is kept when they unlocked the field', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    $category = Category::create(['name' => 'Alcohol']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Castile lite', 'category_id' => $category->id, 'price' => 10100, 'status' => 'draft', 'sku_manual' => true, 'sku' => 'MY-OWN-1'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('name', 'Castile lite')->value('sku'))->toBe('MY-OWN-1');
});

test('a duplicate manual SKU is a validation error with a plain message, never a SQL error', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    $category = Category::create(['name' => 'Alcohol']);
    skuProduct($vendor, ['sku' => 'TAKEN-1']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Another', 'category_id' => $category->id, 'price' => 500, 'status' => 'draft', 'sku_manual' => true, 'sku' => 'TAKEN-1'])
        ->call('create')
        ->assertHasFormErrors(['sku' => 'This SKU is already used by another product in this shop.']);

    expect(Product::where('name', 'Another')->exists())->toBeFalse();
});

test('editing a product onto another product\'s SKU is refused, but keeping its own is fine', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    skuProduct($vendor, ['name' => 'Other', 'sku' => 'TAKEN-2']);
    $mine = skuProduct($vendor, ['sku' => 'MINE-1']);

    Livewire::test(EditProduct::class, ['record' => $mine->getRouteKey()])
        ->fillForm(['sku' => 'TAKEN-2'])
        ->call('save')
        ->assertHasFormErrors(['sku']);

    Livewire::test(EditProduct::class, ['record' => $mine->getRouteKey()])
        ->fillForm(['sku' => 'MINE-1'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// ── Backfill ────────────────────────────────────────────────────────────────

test('the backfill command fills only products with no SKU, and can be run dry', function () {
    [, $vendor] = skuShop();
    [, $other]  = skuShop('Other');

    $kept   = skuProduct($vendor, ['sku' => 'KEEP-1']);
    $blank  = skuProduct($vendor, ['name' => 'Blank one']);
    $theirs = skuProduct($other, ['name' => 'Their blank']);

    // The model fills a SKU on save, so blank them underneath it.
    DB::table('products')->whereIn('id', [$blank->id, $theirs->id])->update(['sku' => null]);

    $this->artisan('products:backfill-sku', ['--vendor' => $vendor->id, '--dry-run' => true])->assertSuccessful();

    expect($blank->fresh()->sku)->toBeNull();

    $this->artisan('products:backfill-sku', ['--vendor' => $vendor->id])->assertSuccessful();

    expect($blank->fresh()->sku)->toMatch('/^ALC-BLANON-\d{4}$/')
        ->and($kept->fresh()->sku)->toBe('KEEP-1')
        // Another shop's product was out of scope.
        ->and($theirs->fresh()->sku)->toBeNull();

    $this->artisan('products:backfill-sku')->assertSuccessful();

    expect($theirs->fresh()->sku)->not->toBeNull();
});

// ── Unit SKUs ───────────────────────────────────────────────────────────────

test('a bigger unit gets its own SKU from the product\'s, and the base unit gets none', function () {
    [, $vendor] = skuShop();
    $product = skuProduct($vendor);

    $crate = ProductUnit::create(['product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 100000]);

    expect($crate->sku)->toBe($product->sku.'-CR12')
        ->and(ProductUnit::where('product_id', $product->id)->where('is_base', true)->value('sku'))->toBeNull();

    $second = ProductUnit::create(['product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 99000]);

    expect($second->sku)->not->toBe($crate->sku);
});

test('typing or scanning a unit SKU at the till finds the product and offers that unit', function () {
    [$owner, $vendor] = skuShop();
    $product = skuProduct($vendor, ['show_in_pos' => true, 'stock_quantity' => 50]);
    $crate   = ProductUnit::create(['product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 100000]);

    Sanctum::actingAs($owner);

    $row = $this->getJson('/api/pos/products/search?vendor_id='.$vendor->id.'&q='.$crate->sku)
        ->assertOk()
        ->assertJsonPath('0.id', $product->id)
        ->json('0');

    expect(collect($row['barcodes'])->firstWhere('barcode', $crate->sku)['unit_id'])->toBe($crate->id);
});

test('the till explains a unit SKU for a product it cannot sell instead of saying it is unknown', function () {
    [$owner, $vendor] = skuShop();
    $product = skuProduct($vendor, ['status' => 'draft']);
    $crate   = ProductUnit::create(['product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 100000]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/pos/products/why-not-sellable?vendor_id='.$vendor->id.'&code='.$crate->sku)
        ->assertOk()
        ->assertJsonPath('found', true);
});

// ── Default unit ────────────────────────────────────────────────────────────

test('the default unit chosen in the form is saved on the product', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    $product = skuProduct($vendor);
    $undo = Repeater::fake();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'base_unit'           => 'Bottle',
            'extraUnits'          => [['name' => 'Crate', 'conversion_factor' => 12, 'price' => 100000, 'is_active' => true, 'barcodes' => []]],
            'default_unit_choice' => 'unit:Crate',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $crate = ProductUnit::where('product_id', $product->id)->where('name', 'Crate')->first();

    expect($product->fresh()->default_unit_id)->toBe($crate->id);

    // Back to the single, and then with the crate gone altogether.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['default_unit_choice' => 'base'])
        ->call('save');

    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    expect($product->fresh()->default_unit_id)->toBe($base->id);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['default_unit_choice' => 'unit:Crate'])
        ->call('save');

    $undo();

    // The crate is removed (what the repeater's delete button does), and the
    // product must not be left pointing at a unit that no longer exists.
    $crate->delete();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->default_unit_id)->toBe($base->id);
});

// ── Gadget fields ───────────────────────────────────────────────────────────

test('specifications and serial tracking are hidden for a shop that is not a gadget shop, and what they hold survives a save', function () {
    [$owner, $vendor] = skuShop();
    skuPanel($owner, $vendor);
    $product = skuProduct($vendor, ['specifications' => ['RAM' => '8GB'], 'track_serial' => true]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsHidden('specifications')
        ->assertFormFieldIsHidden('track_serial')
        ->fillForm(['name' => 'Castile lite 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $product->fresh();

    expect($fresh->name)->toBe('Castile lite 2')
        ->and($fresh->specifications)->toBe(['RAM' => '8GB'])
        ->and($fresh->track_serial)->toBeTrue();
});

test('a gadget shop sees both fields', function () {
    [$owner, $vendor] = skuShop('Gadget', ['enable_gadget_fields' => true]);
    skuPanel($owner, $vendor);
    $product = skuProduct($vendor);

    expect($vendor->usesGadgetFields())->toBeTrue();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsVisible('specifications')
        ->assertFormFieldIsVisible('track_serial');
});
