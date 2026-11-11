<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

/**
 * The business's very first product was just saved, by the person signed in.
 * Fired by MomentRecorder. Products do not record who created them, so a
 * product saved with nobody signed in (a console import, a seeder) fires
 * nothing.
 */
class FirstProductAdded extends Moment
{
    public const KEY = 'first_product_added';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return "Right after the business's first product is added";
    }
}
