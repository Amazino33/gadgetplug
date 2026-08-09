<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

function makePixelProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Pixel Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Pixel Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Pixel Test Product',
        'price'          => 4500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $attrs));
}

test('visiting a product page dispatches a queued ViewContent CAPI event', function () {
    Queue::fake();

    $product = makePixelProduct();

    Volt::test('pages.product-detail', ['product' => $product]);

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) use ($product) {
        $event = $job->payload()['data'][0] ?? null;

        return $event
            && $event['event_name'] === 'ViewContent'
            && $event['custom_data']['content_ids'] === [$product->id]
            && $event['custom_data']['value'] === 4500.0
            && $event['custom_data']['currency'] === 'NGN';
    });
});
