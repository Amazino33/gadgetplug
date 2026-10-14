<?php

namespace App\Rules;

use App\Models\Vendor;
use App\Support\Quantity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A quantity of goods: whole for every shop, or a half-step (½, 1½) for a shop
 * that sells halves. Never zero or less.
 *
 * $halves is the vendor, or a bool the caller has already decided — offline
 * sync passes Quantity::acceptsHalvesOnSync().
 *
 * Pair it with 'numeric' so a non-number gets Laravel's own message.
 */
class QuantityStep implements ValidationRule
{
    /**
     * @param  bool  $allowZero  For a count or a correction, where "none" is a
     *                           real answer. A sale or a purchase line never is.
     */
    public function __construct(
        private readonly Vendor|bool|null $halves,
        private readonly bool $allowZero = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->allowZero && is_numeric($value) && (float) $value === 0.0) {
            return;
        }

        if ($error = Quantity::errorFor($this->halves, $value)) {
            $fail($error);
        }
    }
}
