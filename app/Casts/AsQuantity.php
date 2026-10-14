<?php

namespace App\Casts;

use App\Support\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A quantity of goods: an int when it is whole, a float when it is not.
 *
 * Not Laravel's 'decimal:3', which hands back the string "19.000". That would
 * change every API response and every strict comparison for the shops that
 * never sell halves — a till that reads `19` would start reading `"19.000"`.
 * Through this cast a whole quantity is exactly the int it was before the
 * columns became DECIMAL, and only a half-selling shop ever sees 19.5.
 *
 * Halves are exact in binary floating point, so 19.5 survives arithmetic
 * without drift; everything is still rounded to 3dp on the way in and out.
 */
class AsQuantity implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Quantity::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null || $value === '' ? null : Quantity::of($value);
    }
}
