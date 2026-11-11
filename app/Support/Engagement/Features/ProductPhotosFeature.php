<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\Product;
use App\Support\Engagement\Moments\FirstProductAdded;
use Illuminate\Support\Facades\DB;

class ProductPhotosFeature extends Feature
{
    public function key(): string
    {
        return 'product_photos';
    }

    public function defaultTitle(): string
    {
        return 'Add product photos';
    }

    public function defaultDescription(): string
    {
        return 'Products with photos sell better online and are easier to find at the till.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.resources.products.index';
    }

    protected function usageSources(): array
    {
        return [
            // Media rows do not record who uploaded them, so this one is
            // business-wide only.
            new UsageSource(
                query: fn () => DB::table('media')
                    ->join('products', 'products.id', '=', 'media.model_id')
                    ->where('media.model_type', (new Product)->getMorphClass())
                    ->where('media.collection_name', 'product-images'),
                vendorColumn: 'products.vendor_id',
                usedAtColumn: 'media.created_at',
            ),
        ];
    }

    public function moments(): array
    {
        return [FirstProductAdded::class];
    }
}
