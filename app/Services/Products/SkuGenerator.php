<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Str;

/**
 * Product and unit SKUs: ALC-CASTLI-0042 and ALC-CASTLI-0042-CR12.
 *
 * The product SKU is category (3) + name (up to 6) + a per-shop sequence. The
 * sequence is "one more than the shop's product count", walked forward until
 * the result is free, so deleted products never cause a clash. Two creates at
 * the same moment can still pick the same number; the unique index decides,
 * and Product::performInsert() asks for another.
 */
class SkuGenerator
{
    public function generate(Product $product): string
    {
        return $this->for($product->vendor_id, $product->category_id, $product->name, $product->exists ? $product->id : null);
    }

    /** What a product with these details would be called, without saving anything. */
    public function for(?int $vendorId, ?int $categoryId, ?string $name, ?int $exceptProductId = null, int $skip = 0): string
    {
        $prefix = $this->categoryCode($categoryId).'-'.$this->nameCode($name).'-';

        $seq = Product::where('vendor_id', $vendorId)->count() + 1 + $skip;

        do {
            $sku = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while ($this->productSkuTaken($vendorId, $sku, $exceptProductId));

        return $sku;
    }

    /** {product SKU}-{first two letters of the unit}{how many it holds}. */
    public function forUnit(ProductUnit $unit, string $productSku): string
    {
        $letters = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $unit->name), 0, 2)) ?: 'UN';
        $base    = $productSku.'-'.$letters.max(1, (int) $unit->conversion_factor);

        $sku = $base;
        $n   = 2;

        while (ProductUnit::where('vendor_id', $unit->vendor_id)->where('sku', $sku)
            ->when($unit->exists, fn ($q) => $q->whereKeyNot($unit->getKey()))->exists()) {
            $sku = $base.'-'.$n++;
        }

        return $sku;
    }

    public function categoryCode(?int $categoryId): string
    {
        $name = $categoryId ? Category::whereKey($categoryId)->value('name') : null;
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $name), 0, 3));

        return $code !== '' ? $code : 'GEN';
    }

    /** First four characters of each word, joined, cut to six. */
    public function nameCode(?string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        $code = strtoupper(substr(collect($words)->map(fn ($w) => substr($w, 0, 4))->implode(''), 0, 6));

        return $code !== '' ? $code : 'ITEM';
    }

    private function productSkuTaken(?int $vendorId, string $sku, ?int $exceptProductId): bool
    {
        return Product::where('vendor_id', $vendorId)
            ->where('sku', $sku)
            ->when($exceptProductId, fn ($q) => $q->whereKeyNot($exceptProductId))
            ->exists();
    }
}
