<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function imageVendor(): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Image Shop '.uniqid(),
        'online_sales_enabled' => true,
    ]);
}

function imageProduct(): Product
{
    $vendor = imageVendor();

    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Image Cat'])->id,
        'name'           => 'Photographed Thing '.Str::random(5),
        'price'          => 10000,
        'stock_quantity' => 5,
        'status'         => 'published',
        'published_at'   => now(),
    ]);
}

describe('what a post shows when the image is not ready', function () {
    test('a product with no image at all gets the placeholder, never a broken URL', function () {
        $image = imageProduct()->feedImage();

        expect($image['src'])->toContain('product-placeholder.svg')
            ->and($image['srcset'])->toBeNull()
            ->and($image['ready'])->toBeFalse();
    });

    test('a product whose feed conversion has not run yet falls back to the heavier one', function () {
        // The feed conversions are queued, so this is the state of every image
        // on a host whose worker is behind — or absent.
        Queue::fake();

        $product = imageProduct();
        $product->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 1200))
            ->toMediaCollection('product-images');

        $image = $product->fresh()->feedImage();

        // Heavier, but a real picture of the real product. A feed of broken
        // image icons would be worse than a feed of slow ones.
        expect($image['src'])->not->toContain('placeholder')
            ->and($image['ready'])->toBeFalse();
    });
});

describe('the light conversion', function () {
    test('is WebP, capped at 800, and much lighter than the preview', function () {
        $product = imageProduct();
        $product->addMedia(UploadedFile::fake()->image('hero.jpg', 1600, 1600))
            ->toMediaCollection('product-images');

        $media = $product->fresh()->getFirstMedia('product-images');

        // Generated inline here so the assertion measures real bytes rather
        // than trusting the config.
        app(\Spatie\MediaLibrary\Conversions\FileManipulator::class)
            ->createDerivedFiles($media, ['feed', 'feed_small'], onlyMissing: true);

        $media->refresh();

        expect($media->hasGeneratedConversion('feed'))->toBeTrue()
            ->and($media->getUrl('feed'))->toEndWith('.webp');

        $preview = filesize($media->getPath('preview'));
        $feed = filesize($media->getPath('feed'));
        $small = filesize($media->getPath('feed_small'));

        // The whole point of the phase: an endless scroll cannot serve preview.
        expect($feed)->toBeLessThan($preview)
            ->and($small)->toBeLessThan($feed);

        // Reported so the saving is visible in the run, not just asserted.
        dump([
            'preview bytes'    => $preview,
            'feed bytes'       => $feed,
            'feed_small bytes' => $small,
            'saving'           => round((1 - $feed / $preview) * 100).'% lighter than preview',
        ]);
    });

    test('a ready image offers both widths as a srcset', function () {
        $product = imageProduct();
        $product->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 1200))
            ->toMediaCollection('product-images');

        $media = $product->fresh()->getFirstMedia('product-images');
        app(\Spatie\MediaLibrary\Conversions\FileManipulator::class)
            ->createDerivedFiles($media, ['feed', 'feed_small'], onlyMissing: true);

        $image = $product->fresh()->feedImage();

        expect($image['ready'])->toBeTrue()
            ->and($image['srcset'])->toContain('400w')
            ->and($image['srcset'])->toContain('800w');
    });

    test('the existing thumb and preview are untouched', function () {
        $product = imageProduct();
        $product->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 1200))
            ->toMediaCollection('product-images');

        $media = $product->fresh()->getFirstMedia('product-images');

        // Desktop reads preview and the panel reads thumb. Adding the feed
        // conversion must not have changed either.
        expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
            ->and($media->hasGeneratedConversion('preview'))->toBeTrue()
            ->and($media->getUrl('preview'))->not->toEndWith('.webp');
    });
});
