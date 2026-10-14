<?php

namespace App\Filament\Support;

use App\Models\Vendor;
use App\Rules\QuantityStep;
use App\Support\Quantity;
use Filament\Forms\Components\TextInput;

/**
 * A quantity field in the shop panel that follows the shop's own rule: halves
 * (½, 1½) for a shop that sells them, whole numbers for everyone else.
 *
 * Before this, these fields were numeric with a minimum of 1 and the action
 * cast the answer with (int) — so 1.5 typed by anyone became 1 without a word.
 * Now a whole-number shop is told it must be whole, and a halves shop keeps
 * its half.
 */
final class QuantityInput
{
    public static function apply(TextInput $input, bool $allowZero = false): TextInput
    {
        $halves = fn (): bool => Quantity::allowsHalves(self::tenant());

        return $input
            ->numeric()
            ->step(fn () => $halves() ? 0.5 : 1)
            ->minValue(fn () => $allowZero ? 0 : ($halves() ? 0.5 : 1))
            ->rule(fn () => new QuantityStep(self::tenant(), $allowZero));
    }

    private static function tenant(): ?Vendor
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Vendor ? $tenant : null;
    }
}
