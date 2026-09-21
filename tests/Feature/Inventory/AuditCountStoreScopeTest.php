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

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 12, // vendor-wide mirror: hq(10) + branch(2)
        'status'         => 'published',
    ]);

    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $hq->id, 'quantity' => 10, 'reserved' => 0]);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 2, 'reserved' => 0]);

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
