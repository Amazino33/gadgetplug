<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The storefront's "Trending Right Now" band used to show every category as
// a flat color with a generic keyword-matched icon, never a real photo.
// A curated category image wins if an admin has set one; otherwise the tile
// borrows a photo from one of its own products, so it never depends on the
// admin uploading category art before it looks like a real storefront.

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

function trendingImageVendor(): Vendor
{
    $owner = User::factory()->create();

    return Vendor::create([
        'user_id' => $owner->id, 'name' => 'Trending Image Store ' . uniqid(), 'online_sales_enabled' => true,
    ]);
}

function trendingImageProduct(Vendor $vendor, Category $category, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Trending Image Product ' . uniqid(), 'price' => 1000,
        'stock_quantity' => 5, 'status' => 'published', 'show_online' => true,
    ], $overrides));
}

test('a category with its own uploaded image shows that image on the trending grid', function () {
    $category = Category::create(['name' => 'Own Image Category ' . uniqid()]);
    $category->addMedia(UploadedFile::fake()->image('category.jpg'))->toMediaCollection('category-image');

    $vendor = trendingImageVendor();
    trendingImageProduct($vendor, $category); // has no photo of its own — should be ignored in favour of the category image

    $this->get(route('home'))
        ->assertOk()
        ->assertSee($category->getFirstMediaUrl('category-image', 'thumb'), false);
});

test('a category with no image of its own borrows a photo from one of its products', function () {
    $category = Category::create(['name' => 'Borrowed Image Category ' . uniqid()]);
    $vendor = trendingImageVendor();
    $product = trendingImageProduct($vendor, $category);
    $product->addMedia(UploadedFile::fake()->image('product.jpg'))->toMediaCollection('product-images');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee($product->fresh()->getFirstMediaUrl('product-images', 'thumb'), false);
});

test('a category with neither its own image nor a product photo falls back to the icon placeholder', function () {
    Category::create(['name' => 'Empty Category ' . uniqid()]);

    // The gradient scrim only renders on the image branch — its absence
    // confirms the icon-placeholder path was taken, not a broken <img>.
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('from-black/70', false);
});

test('a category is not offered an unpublished or offline product\'s photo', function () {
    $category = Category::create(['name' => 'Hidden Product Category ' . uniqid()]);
    $vendor = trendingImageVendor();
    $hiddenProduct = trendingImageProduct($vendor, $category, ['status' => 'draft']);
    $hiddenProduct->addMedia(UploadedFile::fake()->image('hidden.jpg'))->toMediaCollection('product-images');

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('from-black/70', false);
});
