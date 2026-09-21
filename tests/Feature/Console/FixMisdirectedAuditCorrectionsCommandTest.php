<?php

use App\Models\AuditSession;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Reproduces the exact incident this command exists to clean up: a solo
// audit at a non-default branch, verified before the store-scoping fix
// shipped, whose correction landed on the vendor's default store while the
// branch actually counted was left exactly as wrong as before.

function misdirectedAuditContext(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Zeelink Tech']);
    $hq     = Store::create(['vendor_id' => $vendor->id, 'name' => 'HQ', 'is_default' => true]);
    $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

    $category = Category::create(['name' => 'Phones '.uniqid()]);

    $product = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => $category->id,
        'name'        => 'Tecno Spark 528',
        'sku'         => 'TS-528',
        'price'       => 65000,
        'store_id'    => $phones->id, // homed at the branch that was counted
        'status'      => 'published',
    ]);

    // Before the bug ran: HQ 12, Zeelink Phones 2 (aggregate 14).
    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $hq->id], ['quantity' => 12, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $phones->id], ['quantity' => 2, 'reserved' => 0]);

    // A solo count found 5 on the shelf at Zeelink Phones. The old bug
    // measured that against the aggregate (14) and wrote -9 to HQ instead.
    $audit = AuditSession::create([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'system_quantity'  => 14,
        'storekeeper_a_id' => $owner->id,
        'storekeeper_b_id' => $owner->id,
        'count_a'          => 5,
        'count_b'          => 5,
        'status'           => 'verified',
    ]);

    InventoryLedger::create([
        'vendor_id'        => $vendor->id,
        'store_id'         => $hq->id,
        'product_id'       => $product->id,
        'transaction_type' => 'audit_correction',
        'quantity_change'  => -9,
        'reference'        => "Audit #{$audit->id}",
        'description'      => 'Inventory count verified. System expected 14, actually found 5.',
    ]);
    ProductStoreStock::where('product_id', $product->id)->where('store_id', $hq->id)->update(['quantity' => 3]);

    return compact('owner', 'vendor', 'hq', 'phones', 'product', 'audit');
}

it('reports the plan without writing anything by default', function () {
    $c = misdirectedAuditContext();

    $this->artisan('inventory:fix-misdirected-audit', [
        'vendor' => $c['vendor']->id,
        'branch' => $c['phones']->id,
    ])->assertSuccessful();

    expect(stockAt($c['product'], $c['hq']))->toBe(3)
        ->and(stockAt($c['product'], $c['phones']))->toBe(2);
});

it('reverses the wrong store and applies the count where it was actually taken', function () {
    $c = misdirectedAuditContext();

    $this->artisan('inventory:fix-misdirected-audit', [
        'vendor'  => $c['vendor']->id,
        'branch'  => $c['phones']->id,
        '--force' => true,
    ])->assertSuccessful();

    // HQ never should have moved — back to its original 12.
    expect(stockAt($c['product'], $c['hq']))->toBe(12)
        // Zeelink Phones now shows what the count actually found: 5.
        ->and(stockAt($c['product'], $c['phones']))->toBe(5);

    expect(InventoryLedger::where('product_id', $c['product']->id)->where('reference', "Reversal — Audit #{$c['audit']->id}")->exists())->toBeTrue()
        ->and(InventoryLedger::where('product_id', $c['product']->id)->where('reference', "Corrected — Audit #{$c['audit']->id}")->exists())->toBeTrue();
});

it('resolves vendor and branch by name as well as id', function () {
    $c = misdirectedAuditContext();

    $this->artisan('inventory:fix-misdirected-audit', [
        'vendor'  => 'zeelink',
        'branch'  => 'zeelink phones',
        '--force' => true,
    ])->assertSuccessful();

    expect(stockAt($c['product'], $c['phones']))->toBe(5);
});

it('leaves a product homed at another branch alone', function () {
    $c = misdirectedAuditContext();

    $elsewhere = Store::create(['vendor_id' => $c['vendor']->id, 'name' => 'Elsewhere']);

    $this->artisan('inventory:fix-misdirected-audit', [
        'vendor'  => $c['vendor']->id,
        'branch'  => $elsewhere->id,
        '--force' => true,
    ])->assertSuccessful();

    // Nothing for this branch to claim — the product's home is Zeelink Phones.
    expect(stockAt($c['product'], $c['hq']))->toBe(3)
        ->and(stockAt($c['product'], $c['phones']))->toBe(2);
});

function stockAt(Product $product, Store $store): int
{
    return (int) (ProductStoreStock::where('product_id', $product->id)->where('store_id', $store->id)->value('quantity') ?? 0);
}
