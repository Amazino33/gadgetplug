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

        if (isset($cart[$productId])) {
            $cart[$productId]['quantity'] += $quantity;
        } else {
            $cart[$productId] = [
                'quantity'  => $quantity,
                'max'       => $product->stock_quantity,
            ];
        }

        // Ensure the cart quantity never exceeds the actual stock
        $cart[$productId]['quantity'] = min($cart[$productId]['quantity'], $product->stock_quantity);

        Session::put('cart', $cart);
    }
}