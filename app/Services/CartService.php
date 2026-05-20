<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Session;

class CartService
{
    /**
     * Add a product to the session cart.
     */
    public function add(Product $product, int $quantity = 1): void
    {
        $cart = Session::get('cart', []);
        $productId = $product->id;

        $available = $product->available_stock;

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

        Session::put('cart', $cart);
    }
}