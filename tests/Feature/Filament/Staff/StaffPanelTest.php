<?php

use App\Filament\Staff\Resources\Products\Pages\ListProducts;
use App\Filament\Staff\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\CatalogueEditorRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

// The /staff panel: back-office staff fix product photos and online names
// across every vendor, without entering the admin panel. Cost never loads.

const STAFF_COST_MARKER = 77777.77;

function staffEditor(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole(CatalogueEditorRoleSeeder::ROLE);

    return $user;
}

function staffSuperAdmin(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $user;
}

function actAsStaffPanel(User $user): void
{
    test()->actingAs($user);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('staff'));
}

function staffProduct(?Vendor $vendor = null, array $overrides = []): Product
{
    $vendor ??= Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Staff Vendor '.uniqid(),
        'online_sales_enabled' => true,
    ]);

    return Product::create(array_merge([
        'vendor_id' => $vendor->id,
        'category_id' => Category::firstOrCreate(['name' => 'Staff Cat'])->id,
        'name' => 'ITEL 2160 STAFF '.uniqid(),
        'brand' => 'itel',
        'price' => 25000,
        'cost_price' => STAFF_COST_MARKER,
        'stock_quantity' => 3,
        'status' => 'published',
    ], $overrides));
}

beforeEach(function () {
    Storage::fake('public');
});

test('the staff panel is registered at /staff', function () {
    $panel = Filament::getPanel('staff');

    expect($panel->getId())->toBe('staff')
        ->and($panel->getPath())->toBe('staff');
});

test('a catalogue editor can access the staff panel and lands on All Products', function () {
    $editor = staffEditor();
    $product = staffProduct();

    expect($editor->canAccessPanel(Filament::getPanel('staff')))->toBeTrue();

    test()->actingAs($editor)->get('/staff')->assertRedirect(ProductResource::getUrl('index', panel: 'staff'));

    test()->actingAs($editor)->get(ProductResource::getUrl('index', panel: 'staff'))
        ->assertOk()
        ->assertSee($product->name);
});

test('a vendor user cannot access the staff panel', function () {
    $product = staffProduct();
    $owner = User::find($product->vendor->user_id);

    expect($owner->canAccessPanel(Filament::getPanel('staff')))->toBeFalse();

    test()->actingAs($owner)->getJson(ProductResource::getUrl('index', panel: 'staff'))->assertForbidden();
});

test('super_admin can access the staff panel too', function () {
    expect(staffSuperAdmin()->canAccessPanel(Filament::getPanel('staff')))->toBeTrue();
});

test('staff see products but never cost', function () {
    actAsStaffPanel(staffEditor());
    $product = staffProduct();

    $component = Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertDontSee('77777')
        ->assertDontSee('77,777');

    expect(json_encode($component->snapshot))->not->toContain('77777');

    // The query itself never selects cost.
    $loaded = ProductResource::getEloquentQuery()->whereKey($product->id)->first();
    expect(array_key_exists('cost_price', $loaded->getAttributes()))->toBeFalse();
});

test('staff can manage a product\'s photos from the staff panel', function () {
    $editor = staffEditor();
    actAsStaffPanel($editor);
    $product = staffProduct();

    $rowBefore = (array) DB::table('products')->find($product->id);

    Livewire::test(ListProducts::class)
        ->mountTableAction('manageImages', $product)
        ->setTableActionData(['images' => [UploadedFile::fake()->image('new.jpg', 60, 60)]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($product->fresh()->getMedia('product-images'))->toHaveCount(1)
        ->and((array) DB::table('products')->find($product->id))->toBe($rowBefore);
});

test('staff can set the online name from the staff panel', function () {
    actAsStaffPanel(staffEditor());
    $product = staffProduct();

    Livewire::test(ListProducts::class)
        ->mountTableAction('editOnlineName', $product)
        ->setTableActionData([
            'online_name' => 'itel 2160 Dual SIM Keypad Phone',
            'meta_description' => 'Dual SIM keypad phone',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($product->fresh()->online_name)->toBe('itel 2160 Dual SIM Keypad Phone')
        ->and($product->fresh()->meta_description)->toBe('Dual SIM keypad phone');
});

test('a catalogue editor is sent to the staff panel after login', function () {
    $editor = staffEditor();

    post('/login', [
        'email' => $editor->email,
        'password' => 'password',
    ])->assertRedirect('/staff');
});
