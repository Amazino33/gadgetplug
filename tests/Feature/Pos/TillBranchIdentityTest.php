<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// The Zeelink Phones incident, 26/09/2026.
//
// A cashier assigned to one branch rang a day of sales. Every one of them came
// back from the server as "Insufficient stock" although the branch held plenty.
// A manager who works across three branches had signed into the vendor panel in
// the same browser, and the till's API trusted that panel session ahead of the
// cashier's own till login — so every sale was attributed to the manager, whose
// branch could not be told, and it quietly fell back to the vendor's default
// store, which held none of those phones.
//
// Three rules keep that from happening again, one per describe block below.

function tbiVendor(): array
{
    $vendor = Vendor::create([
        'user_id'                => User::factory()->create()->id,
        'name'                   => 'Branch Identity '.uniqid(),
        'pos_min_margin_percent' => 0,
    ]);

    $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Phones']);
    $oraimo = Store::create(['vendor_id' => $vendor->id, 'name' => 'Oraimo']);

    return [$vendor, $vendor->defaultStore, $phones, $oraimo];
}

function tbiMember(Vendor $vendor, array $stores = [], ?string $pin = null): User
{
    $user = User::factory()->create(['pos_pin' => $pin ? Hash::make($pin) : null]);
    $vendor->users()->attach($user->id);

    foreach ($stores as $store) {
        $user->stores()->attach($store->id);
    }

    return $user;
}

function tbiProduct(Vendor $vendor, Store $home, int $qty): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'NOKIA 2720 FLIP '.uniqid(),
        'sku'            => 'SKU-'.strtoupper(uniqid()),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $qty,
        'status'         => 'published',
        'show_in_pos'    => true,
    ]);
}

function tbiSale(Product $product, array $extra = []): array
{
    return array_merge([
        'offline_id'      => 'OFF-'.uniqid(),
        'items'           => [[
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => 1000,
            'quantity'     => 1,
            'total'        => 1000,
        ]],
        'payment_method'  => 'cash',
        'total'           => 1000,
        'vat_amount'      => 0,
        'amount_tendered' => 1000,
        'completed_at'    => now()->toIso8601String(),
    ], $extra);
}

function tbiStock(Product $product, Store $store): int
{
    return (int) ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity');
}

// ─── 1. The till trusts only its own login ─────────────────────────

describe('the till trusts only its own login', function () {
    test('a panel session in the same browser does not take over the till', function () {
        [$vendor, $default, $phones, $oraimo] = tbiVendor();
        $product = tbiProduct($vendor, $phones, 10);

        $cashier = tbiMember($vendor, [$phones]);
        $manager = tbiMember($vendor, [$default, $phones, $oraimo]);

        // The manager is signed into the panel in this browser; the till
        // itself is signed in as the cashier.
        $this->actingAs($manager, 'web');
        $token = $cashier->createToken('pos-terminal', ['pos'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [tbiSale($product)]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced');

        $sale = PosSale::sole();

        expect($sale->cashier_id)->toBe($cashier->id)
            ->and($sale->store_id)->toBe($phones->id)
            ->and(tbiStock($product, $phones))->toBe(9);
    });

    test('a panel session alone cannot use the till API', function () {
        [$vendor, $default] = tbiVendor();
        $this->actingAs(tbiMember($vendor, [$default]), 'web');

        $this->getJson("/api/pos/products?vendor_id={$vendor->id}")->assertUnauthorized();
    });
});

// ─── 2. The branch is chosen at sign-in and carried on every sale ──

describe('the branch is fixed when the till signs in', function () {
    test('a one-branch cashier is signed in to that branch without being asked', function () {
        [$vendor, , $phones] = tbiVendor();
        tbiMember($vendor, [$phones], '1234');

        $response = $this->postJson('/api/pos/auth/login', ['vendor_id' => $vendor->id, 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('store.id', $phones->id)
            ->assertJsonPath('store.name', 'Phones');

        expect(PersonalAccessToken::findToken($response->json('token'))->abilities)
            ->toContain('pos-store:'.$phones->id);
    });

    test('someone who works in several branches is asked which one they are in', function () {
        [$vendor, $default, $phones] = tbiVendor();
        tbiMember($vendor, [$default, $phones], '1234');

        $this->postJson('/api/pos/auth/login', ['vendor_id' => $vendor->id, 'pin' => '1234'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'choose_store')
            ->assertJsonCount(2, 'stores');

        // No token is issued until the branch is known.
        expect(PersonalAccessToken::count())->toBe(0);
    });

    test('their choice is bound to the till login', function () {
        [$vendor, $default, $phones] = tbiVendor();
        tbiMember($vendor, [$default, $phones], '1234');

        $response = $this->postJson('/api/pos/auth/login', [
            'vendor_id' => $vendor->id, 'pin' => '1234', 'store_id' => $phones->id,
        ])->assertOk()->assertJsonPath('store.id', $phones->id);

        expect(PersonalAccessToken::findToken($response->json('token'))->abilities)
            ->toContain('pos-store:'.$phones->id);
    });

    test('a branch they do not work in cannot be chosen', function () {
        [$vendor, $default, $phones, $oraimo] = tbiVendor();
        tbiMember($vendor, [$default, $phones], '1234');

        $this->postJson('/api/pos/auth/login', [
            'vendor_id' => $vendor->id, 'pin' => '1234', 'store_id' => $oraimo->id,
        ])->assertStatus(422);

        expect(PersonalAccessToken::count())->toBe(0);
    });

    test('the bound branch decides what the till sells from', function () {
        [$vendor, $default, $phones] = tbiVendor();
        $atPhones  = tbiProduct($vendor, $phones, 5);
        $atDefault = tbiProduct($vendor, $default, 5);

        Sanctum::actingAs(tbiMember($vendor, [$default, $phones]), ['pos', 'pos-store:'.$phones->id]);

        $ids = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->assertOk()->json())->pluck('id');

        expect($ids)->toContain($atPhones->id)->not->toContain($atDefault->id);
    });

    test('a queued sale is recorded at the branch it was rung in', function () {
        [$vendor, $default, $phones] = tbiVendor();
        $product = tbiProduct($vendor, $phones, 10);
        $manager = tbiMember($vendor, [$default, $phones]);

        // Signed in at the default store now, but the sale was rung at Phones
        // earlier in the day — it belongs to Phones.
        Sanctum::actingAs($manager, ['pos', 'pos-store:'.$default->id]);

        $this->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [
            tbiSale($product, ['store_id' => $phones->id, 'cashier_id' => $manager->id]),
        ]])->assertJsonPath('results.0.status', 'synced');

        expect(PosSale::sole()->store_id)->toBe($phones->id)
            ->and(tbiStock($product, $phones))->toBe(9);
    });

    test('a sale naming a branch the syncing user does not work in is refused', function () {
        [$vendor, , $phones, $oraimo] = tbiVendor();
        $product = tbiProduct($vendor, $oraimo, 10);
        $cashier = tbiMember($vendor, [$phones]);
        Sanctum::actingAs($cashier, ['pos']);

        $this->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [
            tbiSale($product, ['store_id' => $oraimo->id, 'cashier_id' => $cashier->id]),
        ]])->assertJsonPath('results.0.status', 'rejected');

        expect(PosSale::count())->toBe(0)
            ->and(tbiStock($product, $oraimo))->toBe(10);
    });

    test('a sale rung by another cashier is not synced under this login', function () {
        [$vendor, , $phones] = tbiVendor();
        $product  = tbiProduct($vendor, $phones, 10);
        $rungBy   = tbiMember($vendor, [$phones]);
        $syncedBy = tbiMember($vendor, [$phones]);
        Sanctum::actingAs($syncedBy, ['pos']);

        $this->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [
            tbiSale($product, ['store_id' => $phones->id, 'cashier_id' => $rungBy->id]),
        ]])->assertJsonPath('results.0.status', 'rejected');

        expect(PosSale::count())->toBe(0);
    });

    test('a sale queued before this change still syncs to the cashier branch', function () {
        // The 24 Zeelink sales are sitting on the till without store_id or
        // cashier_id. Their cashier has one branch, so they go there.
        [$vendor, , $phones] = tbiVendor();
        $product = tbiProduct($vendor, $phones, 10);
        $cashier = tbiMember($vendor, [$phones]);
        Sanctum::actingAs($cashier, ['pos']);

        $this->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [tbiSale($product)]])
            ->assertJsonPath('results.0.status', 'synced');

        expect(PosSale::sole())
            ->store_id->toBe($phones->id)
            ->cashier_id->toBe($cashier->id);
    });
});

// ─── 3. An unclear branch is refused, never guessed ────────────────

describe('an unclear branch is refused', function () {
    test('someone with several branches and an unbound till login is refused, not sent to the default store', function () {
        [$vendor, $default, $phones] = tbiVendor();
        tbiProduct($vendor, $default, 5);
        Sanctum::actingAs(tbiMember($vendor, [$default, $phones]), ['pos']);

        $this->getJson("/api/pos/products?vendor_id={$vendor->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'till_branch_unclear');
    });

    test('an unbound multi-branch login cannot sync a sale that does not name its branch', function () {
        [$vendor, $default, $phones] = tbiVendor();
        $product = tbiProduct($vendor, $default, 5);
        Sanctum::actingAs(tbiMember($vendor, [$default, $phones]), ['pos']);

        $this->postJson('/api/pos/sync', ['vendor_id' => $vendor->id, 'sales' => [tbiSale($product)]])
            ->assertJsonPath('results.0.status', 'rejected');

        expect(PosSale::count())->toBe(0)
            ->and(tbiStock($product, $default))->toBe(5);
    });

    test('a member with no branch in a multi-branch business cannot sign in to the till', function () {
        [$vendor] = tbiVendor();
        tbiMember($vendor, [], '1234');

        $this->postJson('/api/pos/auth/login', ['vendor_id' => $vendor->id, 'pin' => '1234'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_store');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    test('a till login bound to a branch the user has since been taken off is refused', function () {
        [$vendor, $default, $phones] = tbiVendor();
        $cashier = tbiMember($vendor, [$default]);
        Sanctum::actingAs($cashier, ['pos', 'pos-store:'.$phones->id]);

        $this->getJson("/api/pos/products?vendor_id={$vendor->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'till_branch_unclear');
    });

    test('a business with a single shop needs no branch assignments at all', function () {
        $vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'One Shop']);
        $product = tbiProduct($vendor, $vendor->defaultStore, 3);
        Sanctum::actingAs(tbiMember($vendor), ['pos']);

        $ids = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->assertOk()->json())->pluck('id');

        expect($ids)->toContain($product->id);
    });
});
