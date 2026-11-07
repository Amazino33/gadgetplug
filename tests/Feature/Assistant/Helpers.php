<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\Llm\LanguageModel;
use App\Services\VendorRoles;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Assistant\FakeLanguageModel;

/*
 * Shared fixtures for the assistant suites. Loaded with require_once, the
 * same way tests/Feature/Cash does, so each file also runs on its own.
 */

/**
 * A two-branch vendor with an owner and roles seeded.
 *
 * @return array{vendor: Vendor, owner: User, main: Store, branch: Store, product: Product, category: Category}
 */
function assistantVendor(string $name = 'Zeelink Phones', string $branchName = 'Ikeja Branch'): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create(['name' => $name.' Owner']);
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    VendorRoles::seedFor($vendor);

    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => $branchName]);
    $category = Category::firstOrCreate(['name' => 'Assistant Phones']);

    $product = assistantProduct($vendor, $main, $category, 'ITEL 5606 NEW ONE');

    return compact('vendor', 'owner', 'main', 'branch', 'product', 'category');
}

function assistantProduct(Vendor $vendor, Store $store, Category $category, string $name, float $price = 10000, float $cost = 7000): Product
{
    return Product::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => $name,
        'sku' => 'AST-'.Str::random(8),
        'price' => $price,
        'cost_price' => $cost,
        'stock_quantity' => 50,
        'status' => 'published',
    ]);
}

/**
 * A staff member of $vendor holding $role, assigned to $stores (store_user).
 * $extra permissions are granted to the role itself, the way a vendor would
 * from the Roles screen.
 *
 * @param  array<int, Store>  $stores
 * @param  array<int, string>  $extra
 */
function assistantStaff(Vendor $vendor, string $role, array $stores, array $extra = ['use_ai_assistant'], string $name = 'Ada'): User
{
    $user = User::factory()->create(['name' => $name]);
    $vendor->users()->syncWithoutDetaching([$user->id]);

    setPermissionsTeamId($vendor->id);
    $roleModel = Role::where('name', $role)->where('team_id', $vendor->id)->firstOrFail();

    if ($extra !== []) {
        $roleModel->givePermissionTo($extra);
    }

    $user->assignRole($roleModel);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($stores as $store) {
        $store->users()->syncWithoutDetaching([$user->id]);
    }

    return $user->fresh();
}

/** A completed till sale of $qty × $product at $unitPrice, rung by $cashier at $store. */
function assistantSale(Vendor $vendor, Store $store, Product $product, User $cashier, float $unitPrice, int $qty = 1, ?CarbonImmutable $at = null, float $unitCost = 7000): PosSale
{
    $subtotal = $unitPrice * $qty;

    $sale = PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $cashier->id,
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => $subtotal,
        'payment_method' => 'cash',
        'amount_tendered' => $subtotal,
        'status' => 'completed',
        'completed_at' => $at ?? CarbonImmutable::now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'quantity' => $qty,
        'total' => $subtotal,
    ]);

    return $sale;
}

/** Binds a scripted model and returns it, so the test can read what it was shown. */
function assistantFakeModel(array $script): FakeLanguageModel
{
    $fake = new FakeLanguageModel($script);
    app()->instance(LanguageModel::class, $fake);

    return $fake;
}

function assistant(): AssistantService
{
    return app(AssistantService::class);
}
