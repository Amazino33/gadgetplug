<?php

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Archiving moved out of the status field (which is now Draft/Published only)
// and onto the edit page's header, so these cover both halves of that split:
// the actions themselves, and the fact that a two-option field can no longer
// quietly overwrite a third state it cannot represent.

function setUpArchiveVendor(string $status = 'published'): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Archive Test Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Archive Category']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Archivable Widget',
        'price'          => 5000,
        'cost_price'     => 3000,
        'stock_quantity' => 10,
        'status'         => $status,
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function actAsArchiveOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);
}

test('archiving from the edit page sets the product to archived', function () {
    $data = setUpArchiveVendor();
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->callAction('archive');

    expect($data['product']->fresh()->status)->toBe('archived');
});

test('restoring an archived product brings it back as a draft, never straight to live', function () {
    $data = setUpArchiveVendor('archived');
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->callAction('restore');

    expect($data['product']->fresh()->status)->toBe('draft');
});

test('archive is offered on a live product and restore is not', function () {
    $data = setUpArchiveVendor();
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->assertActionVisible('archive')
        ->assertActionHidden('restore');
});

test('restore is offered on an archived product and archive is not', function () {
    $data = setUpArchiveVendor('archived');
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->assertActionVisible('restore')
        ->assertActionHidden('archive');
});

// The reason the status field is disabled rather than given a third option:
// a two-option field holding an unrepresentable value would otherwise submit
// null (failing the required rule) or default back to draft, silently
// resurrecting a product the owner deliberately took out of circulation.
test('saving an archived product from the edit form leaves it archived', function () {
    $data = setUpArchiveVendor('archived');
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->fillForm(['name' => 'Renamed While Archived'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($data['product']->fresh()->name)->toBe('Renamed While Archived')
        ->and($data['product']->fresh()->status)->toBe('archived');
});

test('the create page offers no archive action — there is nothing yet to archive', function () {
    $data = setUpArchiveVendor();
    actAsArchiveOwner($data);

    Livewire::test(CreateProduct::class)
        ->assertOk()
        ->assertActionDoesNotExist('archive')
        ->assertActionDoesNotExist('restore');
});

test('a product can still be moved between draft and published on the form', function () {
    $data = setUpArchiveVendor('draft');
    actAsArchiveOwner($data);

    Livewire::test(EditProduct::class, ['record' => $data['product']->getRouteKey()])
        ->fillForm(['status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($data['product']->fresh()->status)->toBe('published');
});
