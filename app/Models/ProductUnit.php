<?php

namespace App\Models;

use App\Services\Products\SkuGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One way a product is sold — a bottle, a half crate, a crate.
 *
 * conversion_factor is how many base units it stands for; stock always moves
 * in base units. The base unit's own row (is_base) mirrors the product's price
 * and is kept in step by Product, so it is never edited directly.
 */
class ProductUnit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'conversion_factor' => 'integer',
        'price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'half_price' => 'decimal:2',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // The shop always comes from the product, never from the form, so a
        // unit cannot be filed under another shop by a crafted request.
        static::saving(function (ProductUnit $unit) {
            if ($unit->product_id && ! $unit->vendor_id) {
                $unit->vendor_id = Product::whereKey($unit->product_id)->value('vendor_id');
            }

            // A bigger unit answers to its own code: {product SKU}-{CR}{12}.
            // The base unit uses the product's SKU, so it keeps none of its own.
            if (! $unit->is_base && blank($unit->sku) && $unit->product_id) {
                $productSku = Product::whereKey($unit->product_id)->value('sku');

                if (filled($productSku)) {
                    $unit->sku = app(SkuGenerator::class)->forUnit($unit, $productSku);
                }
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** The price for 'retail' or 'wholesale', or null when this unit has no wholesale price. */
    public function priceFor(string $priceType): ?float
    {
        if ($priceType === 'wholesale') {
            return $this->wholesale_price !== null ? (float) $this->wholesale_price : null;
        }

        return (float) $this->price;
    }
}
