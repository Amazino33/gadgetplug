<?php

use App\Actions\Inventory\ProcessAuditCountAction;
use App\Models\AuditSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A solo inventory count names no branch of its own — it is just a product
// row and a number a storekeeper typed in. Resolving it has to fall back to
// whichever store the resolving user is standing in (ActiveStore), and the
// correction it writes has to land THERE — never on the vendor's default
// store, and never measured against the vendor-wide stock mirror. Getting
// this wrong means a branch that was physically counted and found correct
// never gets its own system figure fixed, so POS sales made at that branch
// keep failing "insufficient stock" against a number the count was supposed
// to have corrected.

function auditStoreScopeContext(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Zeelink Tech']);

    $hq     = Store::create(['vendor_id' => $vendor->id, 'name' => 'HQ', 'is_default' => true]);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Branch']);

    $category = Category::create(['name' => 'Chargers '.uniqid()]);

    // Homed at the branch, matching reality: ProductResource only ever lists
    // "Start Inventory Count" for a product homed at the counter's own active
    // store, so a solo count for this product could only ever have been
    // started while standing at the branch, never at HQ.
    //
    // stock_quantity is 0 at creation, not 12: ProductObserver opens a stock
    // row at the product's home store from that figure, and a non-zero one
    // here would collide with the explicit row set below (same reason
    // StockAdjustmentTest's fixture creates at zero and uses updateOrCreate).
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'store_id'       => $branch->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 0, // vendor-wide mirror ends up hq(10) + branch(2)
        'status'         => 'published',
    ]);

    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $hq->id], ['quantity' => 10, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['product_id' => $product->id, 'store_id' => $branch->id], ['quantity' => 2, 'reserved' => 0]);

    return compact('owner', 'vendor', 'hq', 'branch', 'product');
}

it('corrects the branch actually counted, not the vendor default store', function () {
    $c = auditStoreScopeContext();

    // Storekeeper A physically counted the branch and found 5 (not the 2 the
    // system shows there) — the branch's shelf really does hold more than
    // its own row says.
    $audit = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'storekeeper_a_id' => $c['owner']->id,
        'count_a'          => 5,
        'status'           => 'pending',
    ]);

    // Storekeeper B verifies from that same branch's context in the panel —
    // exactly how a second person confirming a physical count would be
    // standing there, not at HQ.
    $this->actingAs($c['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($c['vendor']);
    ActiveStore::set($c['vendor'], $c['owner'], $c['branch']->id);

    $second = User::factory()->create();
    $c['vendor']->users()->attach($second->id);

    app(ProcessAuditCountAction::class)->execute($audit, $second->id, 5);

    expect(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->value('quantity'))
        ->toBe(5)
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['hq']->id)->value('quantity'))
        ->toBe(10);
});

it('corrects the branch the product is homed at, even when the verifier is standing somewhere else', function () {
    // The Audit Sessions page is vendor-wide, not branch-scoped, so nothing
    // stops a storekeeper working out of HQ from picking up and verifying a
    // solo count that was physically taken at another branch. Real product
    // rows always carry the branch they were counted from — ProductResource
    // only ever offers "Start Inventory Count" for a product homed at the
    // counter's own branch — so resolving via the product must win over
    // wherever the verifier's own active store happens to be.
    $c = auditStoreScopeContext();
    $c['product']->update(['store_id' => $c['branch']->id]);

    $audit = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'storekeeper_a_id' => $c['owner']->id,
        'count_a'          => 5,
        'status'           => 'pending',
    ]);

    // Storekeeper B verifies from HQ — a different branch than the one this
    // product, and therefore this count, actually belongs to.
    $this->actingAs($c['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($c['vendor']);
    ActiveStore::set($c['vendor'], $c['owner'], $c['hq']->id);

    $second = User::factory()->create();
    $c['vendor']->users()->attach($second->id);

    app(ProcessAuditCountAction::class)->execute($audit, $second->id, 5);

    // The branch actually counted gets the correction …
    expect(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->value('quantity'))
        ->toBe(5)
        // … and HQ, where the verifier merely happened to be standing, is
        // untouched.
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['hq']->id)->value('quantity'))
        ->toBe(10);
});

it('leaves the branch row untouched when the count matches what it already shows', function () {
    $c = auditStoreScopeContext();

    $audit = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'storekeeper_a_id' => $c['owner']->id,
        'count_a'          => 2,
        'status'           => 'pending',
    ]);

    $this->actingAs($c['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($c['vendor']);
    ActiveStore::set($c['vendor'], $c['owner'], $c['branch']->id);

    $second = User::factory()->create();
    $c['vendor']->users()->attach($second->id);

    app(ProcessAuditCountAction::class)->execute($audit, $second->id, 2);

    expect(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['branch']->id)->value('quantity'))->toBe(2)
        ->and(ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['hq']->id)->value('quantity'))->toBe(10);
});
