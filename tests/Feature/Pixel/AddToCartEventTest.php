<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

function makeAddToCartPixelProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Add To Cart Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Add To Cart Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Add To Cart Pixel Product',
        'price'          => 4500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $attrs));
}

test('adding to cart dispatches a queued AddToCart CAPI event and a matching browser dispatch', function () {
    Queue::fake();

    $product = makeAddToCartPixelProduct(['price' => 3000]);

    Volt::test('pages.product-detail', ['product' => $product])
        ->call('addToCart')
        ->assertDispatched('pixel-add-to-cart');

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) use ($product) {
        $event = $job->payload()['data'][0] ?? null;

        return $event
            && $event['event_name'] === 'AddToCart'
            && $event['custom_data']['content_ids'] === [$product->id]
            && $event['custom_data']['value'] === 3000.0;
    });
});

test('buying now also fires AddToCart before redirecting to checkout', function () {
    Queue::fake();

    $product = makeAddToCartPixelProduct(['price' => 2000]);

    Volt::test('pages.product-detail', ['product' => $product])
        ->call('buyNow')
        ->assertDispatched('pixel-add-to-cart')
        ->assertRedirect(route('checkout'));

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) {
        return ($job->payload()['data'][0]['event_name'] ?? null) === 'AddToCart';
    });
});
