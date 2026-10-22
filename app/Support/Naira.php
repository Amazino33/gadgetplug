<?php

namespace App\Support;

/**
 * Money as people read it here: ₦1,500 — not "NGN 1,500.00".
 *
 * Kobo only when there are kobo (₦1,250.50). One place, so every admin screen
 * writes amounts the same way.
 */
final class Naira
{
    public static function format(float|int|string|null $amount, string $empty = '—'): string
    {
        if ($amount === null || $amount === '') {
            return $empty;
        }

        $amount = (float) $amount;
        $decimals = abs($amount - round($amount)) >= 0.005 ? 2 : 0;

        return ($amount < 0 ? '−' : '').'₦'.number_format(abs($amount), $decimals);
    }
}
