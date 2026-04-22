<?php

use App\Models\Product;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public $cartItems = [];
    public $total = 0;

    public function mount(): void
    {
        $this->loadCart();
    }

    #[On('cart-updated')]
    public function loadCart(): void
    {
        $cart = Session::get('cart', []);
        $this->cartItems = [];
        $this->total = 0;

        foreach ($cart as $productId => $item) {
            $product = Product::find($productId);
            if ($product) {
                $this->cartItems[] = [
                    'id'        => $productId,
                    'name'      => $product->name,
                    'price'     => $product->price,
                    'quantity'  => $item['quantity'],
                    'subtotal'  => $product->price * $item['quantity'],
                    'max'       => $product->stock_quantity,
                ];
                $this->total += $product->price * $item['quantity'];
            }
        }
    }

    public function updateQuantity($productId, $quantity): void
    {
        $cart = Session::get('cart', []);
        if (isset($cart[$productId])) {
            $quantity = max(1, min($quantity, $cart[$productId]['max'] ?? 99));
            $cart[$productId]['quantity'] = $quantity;
            Session::put('cart', $cart);
            session()->flash('success', 'Cart updated!');
            $this->dispatch('cart-updated');
            $this->loadCart();
        }
    }

    public function removeItem($productId): void
    {
        $cart = Session::get('cart', []);
        unset($cart[$productId]);
        Session::put('cart', $cart);
        session()->flash('success', 'Item removed from cart!');
        $this->dispatch('cart-updated');
        $this->loadCart();
    }

    public function clearCart(): void
    {
        Session::forget('cart');
        session()->flash('success', 'Cart cleared!');
        $this->dispatch('cart-updated');
        $this->loadCart();
    }
}; ?>
<div>
<x-layouts.storefront>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-8">Shopping Cart</h1>

            @if (count($cartItems) > 0)
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking wider">Product</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking wider">Price</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking wider">Quantity</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking wider">Subtotal</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($cartItems as $item)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['name'] }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">${{ number_format($item['price'], 2) }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <input  type="number"
                                                    wire:model.live="cartItems.{{ $loop->index }}.quantity"
                                                    wire:change="updateQuantity({{ $item['id'] }}, $event.target.value)"
                                                    min="1"
                                                    max="{{ $item['max'] }}"
                                                    class="w-20 round-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Max: {{ $item['max'] }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">${{ number_format($item['subtotal'], 2) }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <button class="text-red-600 hover:text-red-900 dark:hover:text-red-400" wire:click="removeItem({{ $item['id'] }})">Remove</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <td colspan="3" class="px-6 px-4 text-right text-sm font-medium text-gray-900 dark:text-white">Total:</td>
                                <td class="px-6 px-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">${{ number_format($total, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900 flex justify-between">
                        <button class="text-red-600 hover:text-red-900 dark:hover:text-red-400 font-medium" wire:click="clearCart">Clear Cart</button>
                        <a href="{{ route('checkout') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition">Proceed to Checkout</a>
                    </div>
                </div>
            @else
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-8 text-center">
                    <p class="text-gray-600 dark:text-gray-400 mb-4">Your cart is empty</p>
                    <a href="{{ route('home') }}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition">Continue Shopping</a>
                </div>
            @endif
        </div>
    </div>
</x-layouts.storefront>
</div>
