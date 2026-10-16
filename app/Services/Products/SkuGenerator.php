<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product and unit SKUs, in whichever style the shop chose in Store Profile.
 *
 *   category_name  ALC-CASTLI-0042  category (3) + name (up to 6) + shop sequence
 *   serial         8226             one more than the highest purely numeric SKU
 *   prefix_serial  VWD-8226         the same, behind the shop's prefix
 *   manual         nothing is generated; the shop types every SKU
 *
 * A candidate is walked forward until it is free, so deleted products never
 * cause a clash. Two creates at the same moment can still pick the same one;
 * the unique index decides, and Product::performInsert() asks for another.
 */
class SkuGenerator
{
    public const STYLE_CATEGORY_NAME = 'category_name';
    public const STYLE_SERIAL        = 'serial';
    public const STYLE_PREFIX_SERIAL = 'prefix_serial';
    public const STYLE_MANUAL        = 'manual';

    public const STYLES = [
        self::STYLE_CATEGORY_NAME,
        self::STYLE_SERIAL,
        self::STYLE_PREFIX_SERIAL,
        self::STYLE_MANUAL,
    ];

    public function generate(Product $product): string
    {
        return $this->for($product->vendor_id, $product->category_id, $product->name, $product->exists ? $product->id : null);
    }

    /** The shop's style, defaulting to the one every shop started on. */
    public function styleFor(?int $vendorId): string
    {
        $style = $vendorId ? Vendor::whereKey($vendorId)->value('sku_style') : null;

        return in_array($style, self::STYLES, true) ? $style : self::STYLE_CATEGORY_NAME;
    }

    public function isManual(?int $vendorId): bool
    {
        return $this->styleFor($vendorId) === self::STYLE_MANUAL;
    }

    /** What a product with these details would be called, without saving anything. */
    public function for(?int $vendorId, ?int $categoryId, ?string $name, ?int $exceptProductId = null, int $skip = 0): string
    {
        $vendor = $vendorId ? Vendor::find($vendorId, ['id', 'sku_style', 'sku_prefix']) : null;

        return $this->build(
            $vendorId,
            $this->styleFor($vendorId),
            $vendor?->sku_prefix,
            $categoryId,
            $name,
            $exceptProductId,
            $skip,
        );
    }

    /**
     * What the next product would be called under a style that may not be saved
     * yet - the example shown beside the setting in Store Profile.
     */
    public function example(int $vendorId, string $style, ?string $prefix): string
    {
        return match ($style) {
            self::STYLE_CATEGORY_NAME => $this->build($vendorId, $style, null, null, 'Sample item'),
            self::STYLE_SERIAL, self::STYLE_PREFIX_SERIAL => $this->build($vendorId, $style, $prefix, null, null),
            default => '',
        };
    }

    private function build(?int $vendorId, string $style, ?string $prefix, ?int $categoryId, ?string $name, ?int $exceptProductId = null, int $skip = 0): string
    {
        if ($style === self::STYLE_MANUAL) {
            return '';
        }

        if ($style === self::STYLE_SERIAL || $style === self::STYLE_PREFIX_SERIAL) {
            $lead = $style === self::STYLE_PREFIX_SERIAL ? $this->cleanPrefix($prefix).'-' : '';
            $n    = $this->highestSerial($vendorId, $lead) + 1 + $skip;

            do {
                $sku = $lead.$n;
                $n++;
            } while ($this->productSkuTaken($vendorId, $sku, $exceptProductId));

            return $sku;
        }

        $head = $this->categoryCode($categoryId).'-'.$this->nameCode($name).'-';
        $seq  = Product::where('vendor_id', $vendorId)->count() + 1 + $skip;

        do {
            $sku = $head.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while ($this->productSkuTaken($vendorId, $sku, $exceptProductId));

        return $sku;
    }

    /** Letters and digits only, upper case, at most six. */
    public function cleanPrefix(?string $prefix): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix), 0, 6));
    }

    /**
     * The largest number among this shop's SKUs that are exactly {lead}{digits}.
     * A shop that has used 1, 2, 3 ... 8225 gets 8225 back; one that has used
     * none gets 0, so the sequence starts at 1.
     */
    private function highestSerial(?int $vendorId, string $lead): int
    {
        $query = Product::where('vendor_id', $vendorId)->where('sku', 'like', $lead.'%');

        if (DB::getDriverName() === 'mysql') {
            // At most 15 digits, so the cast cannot overflow.
            return (int) $query
                ->whereRaw('sku REGEXP ?', ['^'.$lead.'[0-9]{1,15}$'])
                ->selectRaw('MAX(CAST(SUBSTRING(sku, '.(strlen($lead) + 1).') AS UNSIGNED)) as top')
                ->value('top');
        }

        return (int) $query->pluck('sku')
            ->filter(fn ($sku) => preg_match('/^'.preg_quote($lead, '/').'[0-9]{1,15}$/', (string) $sku))
            ->map(fn ($sku) => (int) substr($sku, strlen($lead)))
            ->max();
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
