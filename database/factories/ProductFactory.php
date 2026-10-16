<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Products for tests.
 *
 * Product has no HasFactory trait, so call this as ProductFactory::new().
 *
 * A photo is NOT the default. Since 887f13f a product with no photo is hidden
 * from the storefront (Product::scopeVisibleOnline) — a business rule, not a
 * test inconvenience — so a test that wants a customer to see a product says so
 * with ->withPhoto(), and a test that creates one without it must still find it
 * hidden.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'vendor_id'      => Vendor::factory()->onlineSalesEnabled(),
            'category_id'    => fn () => Category::firstOrCreate(['name' => 'Factory Category'])->id,
            'name'           => 'Factory Product '.Str::random(6),
            'price'          => 10000,
            'stock_quantity' => 5,
            'status'         => 'published',
        ];
    }

    /** A product a customer can see: it has a photo in the storefront collection. */
    public function withPhoto(): static
    {
        return $this->afterCreating(fn (Product $product) => self::attachPhoto($product));
    }

    /**
     * Give an existing product a storefront photo, cheaply.
     *
     * Writes the media row only — no file, no image processing, no conversions —
     * because visibility only asks whether a product-images row exists, and URL
     * generation never reads the file. Tests that are actually about images
     * should add a real one with addMedia() instead.
     *
     * Static so fixtures that build products some other way (Product::create in
     * a shared helper, a published VendorLink listing) can use it too.
     */
    public static function attachPhoto(Product $product): Media
    {
        $order = (int) $product->media()->where('collection_name', 'product-images')->max('order_column');

        return $product->media()->create([
            'uuid'                  => (string) Str::uuid(),
            'collection_name'       => 'product-images',
            'name'                  => 'test-photo',
            'file_name'             => 'test-photo.jpg',
            'mime_type'             => 'image/jpeg',
            'disk'                  => 'public',
            'conversions_disk'      => 'public',
            'size'                  => 1024,
            'manipulations'         => [],
            'custom_properties'     => [],
            'generated_conversions' => [],
            'responsive_images'     => [],
            'order_column'          => $order + 1,
        ]);
    }
}
