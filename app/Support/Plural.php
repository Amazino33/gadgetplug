<?php

namespace App\Support;

/** Counted nouns Str::plural gets wrong ("LGAS"). */
final class Plural
{
    public static function lgas(int $count): string
    {
        return $count === 1 ? 'LGA' : 'LGAs';
    }
}
