<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function purgeProductsVendor(): Vendor
{
    $owner = User::factory()->create();

    return Vendor::create(['user_id' => $owner->id, 'name' => 'Purge Store', 'slug' => 'purge-store']);
}

function purgeProductsProduct(Vendor $vendor, string $name): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Purge Cat'])->id,
        'name'           => $name,
        'price'          => 1000,
        'stock_quantity' => 3,
        'status'         => 'published',
    ]);
}

test('a dry run reports but deletes nothing', function () {
    $vendor = purgeProductsVendor();
    purgeProductsProduct($vendor, 'A');
    purgeProductsProduct($vendor, 'B');

    $this->artisan('products:purge', ['vendor' => $vendor->slug])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('force deletes a clean catalogue', function () {
    $vendor = purgeProductsVendor();
    purgeProductsProduct($vendor, 'A');
    purgeProductsProduct($vendor, 'B');

    $this->artisan('products:purge', ['vendor' => $vendor->slug, '--force' => true])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(0);
});

test('it never touches another vendor', function () {
    $mine  = purgeProductsVendor();
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other']);
    purgeProductsProduct($mine, 'A');
    purgeProductsProduct($other, 'Theirs');

    $this->artisan('products:purge', ['vendor' => $mine->slug, '--force' => true])->assertSuccessful();

    expect(Product::where('vendor_id', $mine->id)->count())->toBe(0)
        ->and(Product::where('vendor_id', $other->id)->count())->toBe(1);
});

// The important guard: order_items cascades, so deleting a sold product would
// silently destroy the order line it belongs to.
test('a product with order history is kept back by default', function () {
    $vendor = purgeProductsVendor();
    $clean  = purgeProductsProduct($vendor, 'Clean');
    $sold   = purgeProductsProduct($vendor, 'Sold');

    $order = Order::create([
        'reference' => 'GP-PURGE1', 'customer_name' => 'A', 'customer_email' => 'a@b.c',
        'customer_phone' => '080', 'shipping_address' => 'x', 'total_amount' => 1000,
        'status' => 'paid', 'payment_method' => 'paystack',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $sold->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 1000,
    ]);

    $this->artisan('products:purge', ['vendor' => $vendor->slug, '--force' => true])->assertSuccessful();

    expect(Product::find($clean->id))->toBeNull()
        ->and(Product::find($sold->id))->not->toBeNull()
        ->and(OrderItem::where('order_id', $order->id)->count())->toBe(1);
});

test('with-history removes them too', function () {
    $vendor = purgeProductsVendor();
    $sold   = purgeProductsProduct($vendor, 'Sold');

    $order = Order::create([
        'reference' => 'GP-PURGE2', 'customer_name' => 'A', 'customer_email' => 'a@b.c',
        'customer_phone' => '080', 'shipping_address' => 'x', 'total_amount' => 1000,
        'status' => 'paid', 'payment_method' => 'paystack',
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $sold->id, 'vendor_id' => $vendor->id,
        'quantity' => 1, 'unit_price' => 1000,
    ]);

    $this->artisan('products:purge', [
        'vendor' => $vendor->slug, '--force' => true, '--with-history' => true,
    ])->assertSuccessful();

    expect(Product::find($sold->id))->toBeNull();
});

test('an unknown vendor fails cleanly', function () {
    $this->artisan('products:purge', ['vendor' => 'no-such-vendor'])->assertFailed();
});

test('a vendor can be found by name fragment', function () {
    $vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Chip Gadget',
        'slug'    => 'chip-gadget',
    ]);
    purgeProductsProduct($vendor, 'A');

    $this->artisan('products:purge', ['vendor' => 'chip gadget', '--force' => true])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(0);
});

// Guessing which vendor to empty is exactly the mistake worth preventing.
test('an ambiguous name refuses to delete anything', function () {
    $a = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Chip Gadget', 'slug' => 'chip-gadget']);
    $b = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Chip Gadget Ikeja', 'slug' => 'chip-gadget-ikeja']);
    purgeProductsProduct($a, 'A');
    purgeProductsProduct($b, 'B');

    $this->artisan('products:purge', ['vendor' => 'Chip Gadget', '--force' => true])
        ->assertFailed();

    expect(Product::where('vendor_id', $a->id)->count())->toBe(1)
        ->and(Product::where('vendor_id', $b->id)->count())->toBe(1);
});

// Product implements HasMedia; Spatie cleans images from the model's deleting
// event, which a mass whereIn()->delete() would skip entirely.
test('deleting a product also removes its media rows', function () {
    $vendor  = purgeProductsVendor();
    $product = purgeProductsProduct($vendor, 'With Image');

    // A real 1x1 PNG: Spatie generates conversions on add, so fake bytes fail.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $product->addMediaFromString($png)
        ->usingFileName('shot.png')
        ->toMediaCollection('product-images');

    expect($product->fresh()->getMedia('product-images'))->toHaveCount(1);
    $mediaId = $product->fresh()->getFirstMedia('product-images')->id;

    $this->artisan('products:purge', ['vendor' => $vendor->slug, '--force' => true])
        ->assertSuccessful();

    expect(Product::find($product->id))->toBeNull()
        ->and(Spatie\MediaLibrary\MediaCollections\Models\Media::find($mediaId))->toBeNull();
});

// ── Restricting history, and clearing one branch ───────────────────────────
//
// blind_count_entries, pos_sale_items and procurement_items all restrict
// rather than cascade. --with-history used to walk straight into a foreign-key
// error on any of them, and the report called count entries a cascade — the
// exact case anyone who has run an inventory count is in.

function purgeCountedProduct(Vendor $vendor, string $name): Product
{
    $product = purgeProductsProduct($vendor, $name);

    $session = App\Models\BlindCountSession::create([
        'vendor_id'        => $vendor->id,
        'status'           => 'a_counting',
        'frequency'        => 'daily',
        'product_order'    => [$product->id],
        'storekeeper_a_id' => $vendor->user_id,
    ]);

    App\Models\BlindCountEntry::create([
        'blind_count_session_id' => $session->id,
        'user_id'                => $vendor->user_id,
        'product_id'             => $product->id,
        'position'               => 1,
        'count'                  => 3,
    ]);

    return $product;
}

test('a counted product is held back by default, and named as a blocker', function () {
    $vendor  = purgeProductsVendor();
    $counted = purgeCountedProduct($vendor, 'Counted');

    $this->artisan('products:purge', ['vendor' => $vendor->slug, '--force' => true])
        ->expectsOutputToContain('inventory count entries')
        ->assertSuccessful();

    expect(Product::find($counted->id))->not->toBeNull();
});

test('with-history clears the count entries and removes the product', function () {
    $vendor  = purgeProductsVendor();
    $counted = purgeCountedProduct($vendor, 'Counted');

    // Before the fix this threw a foreign-key violation and rolled back.
    $this->artisan('products:purge', [
        'vendor' => $vendor->slug, '--force' => true, '--with-history' => true,
    ])->assertSuccessful();

    expect(Product::find($counted->id))->toBeNull()
        ->and(App\Models\BlindCountEntry::where('product_id', $counted->id)->count())->toBe(0);
});

test('only the named branch is cleared, never its neighbours', function () {
    $vendor = purgeProductsVendor();
    $here   = App\Models\Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $there  = App\Models\Store::create([
        'vendor_id' => $vendor->id, 'name' => 'Itel Home', 'is_default' => false,
    ]);

    $stays = purgeProductsProduct($vendor, 'Stays Here');
    $stays->update(['store_id' => $here->id]);

    $goes = purgeProductsProduct($vendor, 'Goes Away');
    $goes->update(['store_id' => $there->id]);

    $this->artisan('products:purge', [
        'vendor' => $vendor->slug, '--store' => 'Itel Home', '--force' => true,
    ])->assertSuccessful();

    expect(Product::find($goes->id))->toBeNull()
        ->and(Product::find($stays->id))->not->toBeNull();
});

test('a branch can be cleared without naming its vendor', function () {
    $vendor = purgeProductsVendor();
    $there  = App\Models\Store::create([
        'vendor_id' => $vendor->id, 'name' => 'Itel Home', 'is_default' => false,
    ]);

    $goes = purgeProductsProduct($vendor, 'Goes Away');
    $goes->update(['store_id' => $there->id]);

    $this->artisan('products:purge', ['--store' => 'Itel Home', '--force' => true])
        ->assertSuccessful();

    expect(Product::find($goes->id))->toBeNull();
});

test('a branch belonging to another vendor is refused', function () {
    $vendor = purgeProductsVendor();

    $otherOwner  = User::factory()->create();
    $otherVendor = Vendor::create(['user_id' => $otherOwner->id, 'name' => 'Other Co', 'slug' => 'other-co']);
    $foreign     = App\Models\Store::create([
        'vendor_id' => $otherVendor->id, 'name' => 'Foreign Branch', 'is_default' => false,
    ]);

    $this->artisan('products:purge', [
        'vendor' => $vendor->slug, '--store' => 'Foreign Branch', '--force' => true,
    ])->assertFailed();

    expect(App\Models\Store::find($foreign->id))->not->toBeNull();
});

test('an unknown branch fails cleanly and deletes nothing', function () {
    $vendor = purgeProductsVendor();
    $product = purgeProductsProduct($vendor, 'Untouched');

    $this->artisan('products:purge', [
        'vendor' => $vendor->slug, '--store' => 'no-such-branch', '--force' => true,
    ])->assertFailed();

    expect(Product::find($product->id))->not->toBeNull();
});

test('naming neither a vendor nor a branch fails rather than guessing', function () {
    $this->artisan('products:purge')->assertFailed();
});
