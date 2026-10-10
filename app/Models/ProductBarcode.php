<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A barcode that identifies a product, and optionally which unit of it.
 *
 * product_unit_id null means the base unit. Unique per shop.
 */
class ProductBarcode extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (ProductBarcode $barcode) {
            $barcode->barcode = trim((string) $barcode->barcode);

            if ($barcode->product_id && ! $barcode->vendor_id) {
                $barcode->vendor_id = Product::whereKey($barcode->product_id)->value('vendor_id');
            }

            // A unit barcode inherits its product from the unit, so the form
            // only has to know the unit.
            if ($barcode->product_unit_id && ! $barcode->product_id) {
                $unit = ProductUnit::find($barcode->product_unit_id);
                $barcode->product_id = $unit?->product_id;
                $barcode->vendor_id ??= $unit?->vendor_id;
            }

            // Said plainly rather than left to the unique index, whose error
            // would reach the person as a crash — the usual cause is the same
            // code typed on two units of one product.
            $taken = static::where('vendor_id', $barcode->vendor_id)
                ->where('barcode', $barcode->barcode)
                ->when($barcode->exists, fn ($q) => $q->whereKeyNot($barcode->getKey()))
                ->exists();

            if ($taken) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'barcode' => "Barcode {$barcode->barcode} is already used in this shop.",
                ]);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
