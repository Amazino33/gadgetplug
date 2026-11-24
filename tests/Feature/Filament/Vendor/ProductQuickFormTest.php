<?php

use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Products\QuickProductForm;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The product pop-up on the Products list: filled in the browser, saved once.

function quickFormContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Quick Form Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Drinks']);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('owner', 'vendor', 'category');
}

function quickFormInput(Category $category, array $overrides = []): array
{
    return array_replace([
        'name'                => 'Malta Guinness 33cl',
        'sku'                 => '',
        'category_id'         => (string) $category->id,
        'store_id'            => (string) Store::where('vendor_id', filament()->getTenant()->id)->value('id'),
        'cost_price'          => '350',
        'price'               => '450',
        'status'              => 'published',
        'base_unit'           => 'Bottle',
        'default_unit_choice' => 'base',
        'extraUnits'          => [],
        'barcode'             => '',
        'extraBaseBarcodes'   => [],
        'photos'              => [],
        'tags'                => [],
        'new_tags'            => [],
        'specifications'      => [],
        'low_stock_threshold' => '5',
        'show_in_pos'         => true,
        'show_online'         => true,
    ], $overrides);
}

test('a new product is saved with its units, barcodes, tags and a new supplier in one go', function () {
    $ctx = quickFormContext();
    $existingTag = Tag::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Cold']);

    $product = app(QuickProductForm::class)->save($ctx['vendor'], null, quickFormInput($ctx['category'], [
        'barcode'             => '6001',
        'extraBaseBarcodes'   => ['6002', ''],
        'extraUnits'          => [[
            'id' => null, 'name' => 'Crate', 'conversion_factor' => '24', 'price' => '9700',
            'wholesale_price' => '9400', 'is_active' => true, 'barcodes' => ['7001'],
        ]],
        'default_unit_choice' => 'unit:Crate',
        'tags'                => [(string) $existingTag->id],
        'new_tags'            => ['Malt'],
        'new_supplier'        => ['name' => 'Guinness Depot', 'phone' => '0800'],
    ]));

    expect($product->name)->toBe('Malta Guinness 33cl')
        ->and($product->status)->toBe('published')
        ->and($product->sku)->not->toBeEmpty()
        ->and((float) $product->cost_price)->toBe(350.0);

    $crate = ProductUnit::where('product_id', $product->id)->where('name', 'Crate')->first();
    expect($crate->conversion_factor)->toBe(24)
        ->and((float) $crate->wholesale_price)->toBe(9400.0)
        ->and($product->default_unit_id)->toBe($crate->id);

    expect(ProductBarcode::where('product_id', $product->id)->pluck('barcode')->sort()->values()->all())
        ->toBe(['6001', '6002', '7001'])
        ->and(ProductBarcode::where('barcode', '7001')->value('product_unit_id'))->toBe($crate->id);

    expect($product->tags->pluck('name')->sort()->values()->all())->toBe(['Cold', 'Malt'])
        ->and(Supplier::find($product->supplier_id)?->name)->toBe('Guinness Depot');
});

test('editing moves, adds and removes barcodes and units as the form shows them', function () {
    $ctx = quickFormContext();
    $form = app(QuickProductForm::class);

    $product = $form->save($ctx['vendor'], null, quickFormInput($ctx['category'], [
        'barcode'           => '6001',
        'extraBaseBarcodes' => ['6002', '6003'],
        'extraUnits'        => [
            ['name' => 'Crate', 'conversion_factor' => '24', 'price' => '9700', 'is_active' => true, 'barcodes' => ['7001']],
            ['name' => 'Pack', 'conversion_factor' => '6', 'price' => '2600', 'is_active' => true, 'barcodes' => []],
        ],
    ]));

    $loaded = $form->load($product->fresh());
    expect($loaded['extraBaseBarcodes'])->toBe(['6002', '6003'])
        ->and(collect($loaded['extraUnits'])->pluck('name')->all())->toBe(['Crate', 'Pack']);

    // The old main code becomes an alternative, 6002 becomes the main one, 6003
    // is dropped, the crate's code moves to the base unit, the pack goes.
    $crate = collect($loaded['extraUnits'])->firstWhere('name', 'Crate');
    $form->save($ctx['vendor'], $product->fresh(), array_replace($loaded, [
        'barcode'           => '6002',
        'extraBaseBarcodes' => ['6001', '7001'],
        'extraUnits'        => [array_replace($crate, ['name' => 'Crate of 24', 'barcodes' => ['7002']])],
    ]));

    $product->refresh();
    expect($product->barcode)->toBe('6002')
        ->and(ProductBarcode::where('product_id', $product->id)->whereNull('product_unit_id')->pluck('barcode')->sort()->values()->all())
        ->toBe(['6001', '6002', '7001'])
        ->and(ProductUnit::where('product_id', $product->id)->where('is_base', false)->pluck('name')->all())->toBe(['Crate of 24'])
        ->and(ProductBarcode::where('barcode', '7002')->value('product_unit_id'))->toBe($crate['id']);
});

test('a barcode another product uses, or one typed twice, is refused beside its own field', function () {
    $ctx = quickFormContext();
    Product::create([
        'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['category']->id,
        'name' => 'Coke', 'sku' => 'COKE', 'barcode' => '5000', 'price' => 300, 'status' => 'published',
    ]);

    try {
        app(QuickProductForm::class)->save($ctx['vendor'], null, quickFormInput($ctx['category'], [
            'barcode'           => '8000',
            'extraBaseBarcodes' => ['5000', '8000'],
        ]));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['extraBaseBarcodes.0', 'extraBaseBarcodes.1'])
            ->and($e->errors()['extraBaseBarcodes.0'][0])->toContain('Coke');
    }

    expect(Product::where('name', 'Malta Guinness 33cl')->exists())->toBeFalse();
});

test('the selling price must be more than the cost price', function () {
    $ctx = quickFormContext();

    expect(fn () => app(QuickProductForm::class)->save($ctx['vendor'], null, quickFormInput($ctx['category'], ['price' => '300'])))
        ->toThrow(ValidationException::class);
});

test('staff who cannot see cost prices never change one', function () {
    $ctx = quickFormContext();
    $product = app(QuickProductForm::class)->save($ctx['vendor'], null, quickFormInput($ctx['category']));

    $manager = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $manager->assignRole('product_manager');
    test()->actingAs($manager);

    $form = app(QuickProductForm::class);
    $loaded = $form->load($product->fresh());
    expect($loaded['cost_price'])->toBe('');

    $form->save($ctx['vendor'], $product->fresh(), array_replace($loaded, ['cost_price' => '1', 'price' => '500']));

    expect((float) $product->fresh()->cost_price)->toBe(350.0)
        ->and((float) $product->fresh()->price)->toBe(500.0);
});

test('the list page carries the pop-up, and saves and loads through it', function () {
    $ctx = quickFormContext();
    Storage::fake('public');

    $page = Livewire::test(ListProducts::class)
        ->assertSee('productQuickForm', false)
        ->set('quickPhotos', [UploadedFile::fake()->image('front.jpg', 400, 400)])
        ->call('saveQuickProduct', quickFormInput($ctx['category'], [
            'photos'            => [['upload' => 0]],
            'extraBaseBarcodes' => ['6100'],
        ]));

    $product = Product::where('name', 'Malta Guinness 33cl')->firstOrFail();
    expect($product->getMedia('product-images'))->toHaveCount(1)
        ->and($page->get('quickPhotos'))->toBe([]);

    $loaded = app(QuickProductForm::class)->load($product);
    expect($loaded['photos'])->toHaveCount(1)
        ->and($loaded['extraBaseBarcodes'])->toBe(['6100']);

    // Saving without the photo in the list removes it.
    Livewire::test(ListProducts::class)
        ->call('saveQuickProduct', array_replace($loaded, ['photos' => []]), $product->id);

    expect($product->fresh()->getMedia('product-images'))->toHaveCount(0);
});

test('a refusal comes back as data for the pop-up, not an exception', function () {
    quickFormContext();

    $component = Livewire::test(ListProducts::class)
        ->call('saveQuickProduct', ['name' => '', 'price' => '']);

    expect(Product::count())->toBe(0);
    $component->assertHasNoErrors();
});

test('the pop-up saves search keywords and forgets learned words a manager removes', function () {
    $ctx = quickFormContext();
    $form = app(QuickProductForm::class);

    $product = $form->save($ctx['vendor'], null, quickFormInput($ctx['category'], ['search_keywords' => ' malt ,Guinness malt,, malt ']));
    expect($product->search_keywords)->toBe('malt, Guinness malt');

    $shortcuts = app(\App\Services\Products\SearchShortcuts::class);
    $shortcuts->record($ctx['vendor']->id, $product->id, 'maltina');
    $shortcuts->record($ctx['vendor']->id, $product->id, 'malta g');

    $loaded = $form->load($product->fresh());
    expect(collect($loaded['learned_words'])->pluck('term')->sort()->values()->all())->toBe(['malta g', 'maltina']);

    $form->save($ctx['vendor'], $product->fresh(), array_replace($loaded, ['forget_words' => ['maltina']]));

    expect(collect($form->load($product->fresh())['learned_words'])->pluck('term')->all())->toBe(['malta g']);
});
