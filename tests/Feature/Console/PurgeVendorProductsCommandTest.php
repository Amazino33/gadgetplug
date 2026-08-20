<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function purgeVendor(): Vendor
{
    $owner = User::factory()->create();

    return Vendor::create(['user_id' => $owner->id, 'name' => 'Purge Store', 'slug' => 'purge-store']);
}

function purgeProduct(Vendor $vendor, string $name): Product
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
    $vendor = purgeVendor();
    purgeProduct($vendor, 'A');
    purgeProduct($vendor, 'B');

    $this->artisan('products:purge', ['vendor' => $vendor->slug])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('force deletes a clean catalogue', function () {
    $vendor = purgeVendor();
    purgeProduct($vendor, 'A');
    purgeProduct($vendor, 'B');

    $this->artisan('products:purge', ['vendor' => $vendor->slug, '--force' => true])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(0);
});

test('it never touches another vendor', function () {
    $mine  = purgeVendor();
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other']);
    purgeProduct($mine, 'A');
    purgeProduct($other, 'Theirs');

    $this->artisan('products:purge', ['vendor' => $mine->slug, '--force' => true])->assertSuccessful();

    expect(Product::where('vendor_id', $mine->id)->count())->toBe(0)
        ->and(Product::where('vendor_id', $other->id)->count())->toBe(1);
});

// The important guard: order_items cascades, so deleting a sold product would
// silently destroy the order line it belongs to.
test('a product with order history is kept back by default', function () {
    $vendor = purgeVendor();
    $clean  = purgeProduct($vendor, 'Clean');
    $sold   = purgeProduct($vendor, 'Sold');

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
    $vendor = purgeVendor();
    $sold   = purgeProduct($vendor, 'Sold');

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
    purgeProduct($vendor, 'A');

    $this->artisan('products:purge', ['vendor' => 'chip gadget', '--force' => true])
        ->assertSuccessful();

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(0);
});

// Guessing which vendor to empty is exactly the mistake worth preventing.
test('an ambiguous name refuses to delete anything', function () {
    $a = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Chip Gadget', 'slug' => 'chip-gadget']);
    $b = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Chip Gadget Ikeja', 'slug' => 'chip-gadget-ikeja']);
    purgeProduct($a, 'A');
    purgeProduct($b, 'B');

    $this->artisan('products:purge', ['vendor' => 'Chip Gadget', '--force' => true])
        ->assertFailed();

    expect(Product::where('vendor_id', $a->id)->count())->toBe(1)
        ->and(Product::where('vendor_id', $b->id)->count())->toBe(1);
});

// Product implements HasMedia; Spatie cleans images from the model's deleting
// event, which a mass whereIn()->delete() would skip entirely.
test('deleting a product also removes its media rows', function () {
    $vendor  = purgeVendor();
    $product = purgeProduct($vendor, 'With Image');

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
