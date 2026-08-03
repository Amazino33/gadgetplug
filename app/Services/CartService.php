<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Session;

class CartService
{
    /**
     * Add a product to the session cart.
     *
     * @return bool false if the product has no available stock at all — the
     *              caller should tell the customer rather than silently add
     *              a zero-quantity line (which previously reached checkout
     *              and produced a ₦0 order with no real items on it).
     */
    public function add(Product $product, int $quantity = 1): bool
    {
        $available = $product->available_stock;

        if ($available <= 0) {
            return false;
        }

        $cart = Session::get('cart', []);
        $productId = $product->id;

        if (isset($cart[$productId])) {
            $cart[$productId]['quantity'] += $quantity;
        } else {
            $cart[$productId] = [
                'quantity'  => $quantity,
                'max'       => $available,
            ];
        }

        // Cap at available stock (physical minus already reserved for other orders)
        $cart[$productId]['quantity'] = min($cart[$productId]['quantity'], $available);
        $cart[$productId]['max']      = $available;

        Session::put('cart', $cart);

        return true;
    }
}