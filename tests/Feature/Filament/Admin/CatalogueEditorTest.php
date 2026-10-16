<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\SupplierLink;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\ProductPolicy;
use Database\Seeders\CatalogueEditorRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// The app turns a 403 on a page load into a friendly redirect (bootstrap/app.php),
// so access is asserted with JSON requests, which still get the raw 403.
//
// The catalogue editor is GadgetPlug back-office staff who fix product photos
// across every vendor. They get into the admin panel, see All Products and
// nothing else there, never see cost, and can change photos and nothing else.

const EDITOR_COST_MARKER = 77777.77;

function catalogueEditor(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole(CatalogueEditorRoleSeeder::ROLE);

    return $user;
}

function catalogueSuperAdmin(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $user;
}

function actAsAdminPanel(User $user): void
{
    test()->actingAs($user);
    setPermissionsTeamId(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function catalogueProduct(?Vendor $vendor = null, array $overrides = []): Product
{
    $vendor ??= Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Catalogue Vendor '.uniqid(),
        'online_sales_enabled' => true,
    ]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Catalogue Cat'])->id,
        'name'           => 'ITEL 2160 OLD ONE '.uniqid(),
        'brand'          => 'itel',
        'price'          => 25000,
        'cost_price'     => EDITOR_COST_MARKER,
        'stock_quantity' => 3,
        'status'         => 'published',
    ], $overrides));
}

function addPhotos(Product $product, int $count): array
{
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $ids[] = $product->addMedia(UploadedFile::fake()->image("photo{$i}.jpg", 50, 50))
            ->toMediaCollection('product-images')->id;
    }

    return $ids;
}

beforeEach(function () {
    Storage::fake('public');
});

// ── Access ──────────────────────────────────────────────────────────────────

test('the migration creates a global catalogue_editor role holding both permissions', function () {
    $role = Role::where('name', 'catalogue_editor')->whereNull('team_id')->sole();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['update_product_media', 'update_product_online_name']);
});

test('the editor is admitted to the admin panel and lands on All Products', function () {
    $editor = catalogueEditor();
    $product = catalogueProduct();

    test()->actingAs($editor)->get('/admin')->assertRedirect(ProductResource::getUrl('index', panel: 'admin'));

    test()->actingAs($editor)->get(ProductResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee($product->name);
});

test('the editor can reach no admin resource or page except All Products', function () {
    actAsAdminPanel(catalogueEditor());
    $panel = Filament::getPanel('admin');

    foreach ($panel->getResources() as $resource) {
        $expected = $resource === ProductResource::class;

        expect($resource::canAccess())->toBe($expected, "{$resource}::canAccess() should be ".($expected ? 'true' : 'false'));

        if (! $expected && array_key_exists('index', $resource::getPages())) {
            test()->getJson($resource::getUrl('index', panel: 'admin'))->assertForbidden();
        }
    }

    foreach ($panel->getPages() as $page) {
        if ($page === Dashboard::class) {
            // Reachable only to be redirected away, and never in the navigation.
            expect($page::shouldRegisterNavigation())->toBeFalse();

            continue;
        }

        expect($page::canAccess())->toBeFalse("{$page}::canAccess() should be false for the editor");
        test()->getJson($page::getUrl(panel: 'admin'))->assertForbidden();
    }
});

test('the editor cannot open the commission edit page', function () {
    actAsAdminPanel(catalogueEditor());
    $product = catalogueProduct();

    test()->getJson(ProductResource::getUrl('edit', ['record' => $product], panel: 'admin'))->assertForbidden();
});

test('a vendor user cannot reach All Products or pass the image policy', function () {
    $product = catalogueProduct();
    $owner = User::find($product->vendor->user_id);

    test()->actingAs($owner)->getJson(ProductResource::getUrl('index', panel: 'admin'))->assertForbidden();

    expect(Gate::forUser($owner)->allows('updateMedia', $product))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('updateOnlineName', $product))->toBeFalse();
});

test('super_admin still sees every admin resource, the cost-side columns and the edit page', function () {
    actAsAdminPanel(catalogueSuperAdmin());
    $product = catalogueProduct(overrides: ['commission_rate' => 4.5]);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        expect($resource::canAccess())->toBeTrue("{$resource} should stay open to super_admin");
    }

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertTableColumnVisible('commission_rate')
        ->assertTableColumnVisible('reseller_discount');

    test()->get(ProductResource::getUrl('edit', ['record' => $product], panel: 'admin'))->assertOk();
});

// Shield can regenerate policies; these two methods are hand-written and must survive.
test('the catalogue policy methods exist on ProductPolicy', function () {
    expect(method_exists(ProductPolicy::class, 'updateMedia'))->toBeTrue()
        ->and(method_exists(ProductPolicy::class, 'updateOnlineName'))->toBeTrue()
        ->and(method_exists(ProductPolicy::class, 'viewCatalogue'))->toBeTrue();
});

// ── Cost never reaches the editor ───────────────────────────────────────────

test('cost price is never loaded, rendered or put in Livewire state for the editor', function () {
    actAsAdminPanel(catalogueEditor());
    $product = catalogueProduct();
    addPhotos($product, 1);

    $component = Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->assertTableColumnHidden('commission_rate')
        ->assertDontSee('77777')
        ->assertDontSee('77,777');

    expect(json_encode($component->snapshot))->not->toContain('77777');

    // Opening the image modal must not smuggle it in either.
    $component->mountTableAction('manageImages', $product);
    expect(json_encode($component->snapshot))->not->toContain('77777');

    // And the query itself never selects it.
    $loaded = ProductResource::getEloquentQuery()->whereKey($product->id)->first();
    expect(array_key_exists('cost_price', $loaded->getAttributes()))->toBeFalse()
        ->and($loaded->profit)->toBeNull();
});

// ── Manage images ───────────────────────────────────────────────────────────

test('the editor can add a photo; no product column changes and the change is logged', function () {
    $editor = catalogueEditor();
    actAsAdminPanel($editor);
    $product = catalogueProduct();
    [$first] = addPhotos($product, 1);
    $firstUuid = $product->getFirstMedia('product-images')->uuid;

    $rowBefore = (array) DB::table('products')->find($product->id);

    Livewire::test(ListProducts::class)
        ->mountTableAction('manageImages', $product)
        ->setTableActionData(['images' => [$firstUuid => $firstUuid, UploadedFile::fake()->image('new.jpg', 60, 60)]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($product->fresh()->getMedia('product-images'))->toHaveCount(2)
        ->and($product->fresh()->getFirstMedia('product-images')->id)->toBe($first)
        ->and((array) DB::table('products')->find($product->id))->toBe($rowBefore);

    $log = Activity::where('event', 'media_updated')->where('subject_id', $product->id)->sole();
    expect($log->causer_id)->toBe($editor->id)
        ->and($log->vendor_id)->toBe($product->vendor_id)
        ->and($log->properties['added'])->toHaveCount(1)
        ->and($log->properties['removed'])->toHaveCount(0);
});

test('the editor can remove and reorder photos, and the first becomes primary', function () {
    actAsAdminPanel(catalogueEditor());
    $product = catalogueProduct();
    [$a, $b, $c] = addPhotos($product, 3);
    $uuids = $product->getMedia('product-images')->pluck('uuid', 'id');

    // Drop B, put C first.
    Livewire::test(ListProducts::class)
        ->mountTableAction('manageImages', $product)
        ->setTableActionData(['images' => [$uuids[$c] => $uuids[$c], $uuids[$a] => $uuids[$a]]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $media = $product->fresh()->getMedia('product-images');

    expect($media->pluck('id')->all())->toBe([$c, $a])
        ->and($product->fresh()->getFirstMedia('product-images')->id)->toBe($c);

    $log = Activity::where('event', 'media_updated')->where('subject_id', $product->id)->sole();
    expect($log->properties['removed'])->toHaveCount(1)
        ->and($log->properties['reordered'])->toBeTrue()
        ->and($log->properties['primary_changed'])->toBeTrue();
});

test('more than eight photos is refused', function () {
    actAsAdminPanel(catalogueEditor());
    $product = catalogueProduct();

    $nine = collect(range(1, 9))->map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 20, 20))->all();

    Livewire::test(ListProducts::class)
        ->mountTableAction('manageImages', $product)
        ->setTableActionData(['images' => $nine])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['images']);

    expect($product->fresh()->getMedia('product-images'))->toHaveCount(0);
});

test('a linked listing offers no image action; its row points at the supplier product', function () {
    actAsAdminPanel(catalogueEditor());
    $supplierProduct = catalogueProduct();
    $reseller = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Reseller Shop', 'online_sales_enabled' => true]);
    $link = SupplierLink::create([
        'reseller_vendor_id' => $reseller->id,
        'supplier_vendor_id' => $supplierProduct->vendor_id,
        'markup_percent'     => 20,
    ]);
    $listing = catalogueProduct($reseller, [
        'source_product_id' => $supplierProduct->id,
        'supplier_link_id'  => $link->id,
        'stock_quantity'    => 0,
        'cost_price'        => null,
    ]);

    Livewire::test(ListProducts::class)
        ->assertTableActionHidden('manageImages', $listing)
        ->assertTableActionVisible('manageImages', $supplierProduct)
        ->assertSee('Images managed on supplier product')
        ->assertSee('Reseller of '.$supplierProduct->vendor->name)
        ->assertSee('Supplier — 1 reseller listing');

    // The permission is there, but the action itself refuses linked listings:
    // forcing the call saves nothing.
    expect(Gate::allows('updateMedia', $listing))->toBeTrue();

    // A hand-crafted mount request (what a tampered browser would send) is
    // refused: nothing mounts, so there is no modal to submit.
    $component = Livewire::test(ListProducts::class)
        ->call('mountAction', 'manageImages', [], ['table' => true, 'recordKey' => (string) $listing->getKey()]);

    expect($component->get('mountedActions'))->toBeEmpty()
        ->and($listing->fresh()->getMedia('product-images'))->toHaveCount(0);
});

test('the no-photo filter finds products without images', function () {
    actAsAdminPanel(catalogueEditor());
    $withPhoto = catalogueProduct();
    addPhotos($withPhoto, 1);
    $without = catalogueProduct();

    Livewire::test(ListProducts::class)
        ->filterTable('no_photo', true)
        ->assertCanSeeTableRecords([$without])
        ->assertCanNotSeeTableRecords([$withPhoto]);
});
