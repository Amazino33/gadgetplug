<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Models\Product;

/**
 * The vendor's very first product was just saved, by the person signed in.
 * Products do not record who created them, so there is nobody to credit when
 * one arrives without a signed-in user (a console import, a seeder).
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

    public function handleProduct(Product $product, PendingMoments $pending): void
    {
        $userId = auth()->id();

        if (! $userId || ! $product->vendor_id) {
            return;
        }

        // limit(2): all that matters is "exactly one", and an import adding
        // three hundred products must not count three hundred rows each time.
        $count = Product::query()->where('vendor_id', $product->vendor_id)->limit(2)->pluck('id')->count();

        if ($count === 1) {
            $pending->record((int) $userId, (int) $product->vendor_id, self::KEY, 1, $this->expiresAt());
        }
    }
}
