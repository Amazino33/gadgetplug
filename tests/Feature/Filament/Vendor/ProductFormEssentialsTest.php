<?php

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function essentialsVendor(): Vendor
{
    $vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Essentials Vendor '.uniqid(),
    ]);

    test()->actingAs($vendor->user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    return $vendor;
}

// ── The point of the whole restructure ──────────────────────────────────────

test('a product saves with nothing but the essentials filled in', function () {
    $vendor   = essentialsVendor();
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    // Name, category, price, status. No brand, no SKU, no barcode, no
    // supplier, no unit, no thresholds, no channels — everything that lives
    // under Advanced settings is left untouched, and the form still saves.
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'        => 'Bare Minimum Product',
            'category_id' => $category->id,
            'price'       => 5000,
            'status'      => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Bare Minimum Product')->firstOrFail();

    expect($product->vendor_id)->toBe($vendor->id)
        ->and((float) $product->price)->toBe(5000.0)
        ->and($product->status)->toBe('published');
});

test('the defaults behind Advanced settings are applied without it being opened', function () {
    essentialsVendor();
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'        => 'Defaults Product',
            'category_id' => $category->id,
            'price'       => 7500,
            'status'      => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Defaults Product')->firstOrFail();

    // low_stock_threshold is required and lives under Advanced. It carries a
    // default precisely so a collapsed section can never block a save with an
    // error on a field the vendor cannot see.
    expect($product->low_stock_threshold)->toBe(5)
        ->and($product->store_id)->not->toBeNull()
        ->and((bool) $product->show_online)->toBeTrue()
        ->and((bool) $product->show_in_pos)->toBeTrue();
});

// ── What is on screen ───────────────────────────────────────────────────────

test('the create form still offers every advanced field, just not up front', function () {
    essentialsVendor();

    $form = Livewire::test(CreateProduct::class);

    // Essentials. Photos are not among them: one can be added later from the
    // edit page, while a product saved with no cost price leaves a dash in
    // every margin report until somebody goes back for it.
    foreach (['name', 'category_id', 'store_id', 'cost_price', 'price', 'status'] as $field) {
        $form->assertFormFieldExists($field);
    }

    // Advanced — present in the schema, collapsed on arrival.
    foreach ([
        'images', 'brand', 'sku', 'barcode', 'supplier_id', 'description',
        'specifications', 'allow_pos_price_override', 'measurement_unit',
        'low_stock_threshold', 'reorder_point', 'preferred_quantity', 'is_service',
        'show_online', 'show_in_pos', 'tags',
    ] as $field) {
        $form->assertFormFieldExists($field);
    }
});

test('the edit form is built the same way, so the two pages cannot drift apart', function () {
    $vendor   = essentialsVendor();
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Editable Product',
        'price'          => 9000,
        'stock_quantity' => 3,
        'status'         => 'published',
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('price')
        ->assertFormFieldExists('status')
        ->assertFormFieldExists('cost_price')
        ->assertFormFieldExists('low_stock_threshold')
        ->assertFormFieldExists('tags');
});

// ── Things that had to keep working across the move ─────────────────────────

test('a selling price below the cost price is refused', function () {
    essentialsVendor();
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    // The two sit side by side in the essentials now, but the rule reads the
    // sibling's state rather than its position — this guards the pairing
    // wherever either field is moved to next.
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'        => 'Loss Maker',
            'category_id' => $category->id,
            'cost_price'  => 8000,
            'price'       => 5000,
            'status'      => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['price']);

    expect(Product::where('name', 'Loss Maker')->exists())->toBeFalse();
});

test('a blank cost price still lets the product save', function () {
    essentialsVendor();
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'        => 'No Cost Known',
            'category_id' => $category->id,
            'price'       => 5000,
            'status'      => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('name', 'No Cost Known')->firstOrFail()->cost_price)->toBeNull();
});

test('an owner picks the home store right in the essentials', function () {
    $vendor = essentialsVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Advanced Branch']);
    $category = Category::create(['name' => 'Essentials Cat '.uniqid()]);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name'        => 'Homed Product',
            'category_id' => $category->id,
            'price'       => 4000,
            'status'      => 'draft',
            'store_id'    => $branch->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('name', 'Homed Product')->firstOrFail()->store_id)->toBe($branch->id);
});

// ── Both essentials selects open from markup, not from a request ────────────

test('the category and home store selects are native, so their options are already on the page', function () {
    essentialsVendor();

    $components = Livewire::test(CreateProduct::class)
        ->instance()
        ->getSchema('form')
        ->getFlatFields();

    // Filament's own select builds its list when clicked, and a searchable one
    // asks the server as you type. Native means the browser already holds the
    // options and the dropdown opens with no request at all.
    expect($components['category_id']->isNative())->toBeTrue()
        ->and($components['category_id']->isSearchable())->toBeFalse()
        ->and($components['store_id']->isNative())->toBeTrue();
});
