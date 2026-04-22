<?php

use Livewire\Volt\Component;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

new class extends Component {
    public $name = '';
    public $email = '';
    public $phone = '';
    public $address = '';

    public $cartItems = [];
    public $total = 0;

    public function mount(): void
    {
        // Load cart data
        $cart = Session::get('cart', []);
        if (empty($cart)) {
            $this->redirectRoute('home');
        }

        // We will calculate the total directly here for simplicity
        foreach ($cart as $productId => $item) {
            $product = Product::find($productId);
            if ($product) {
                $this->cartItems[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                ];
                $this->total += $product->price * $item['quantity'];
            }
        }
    }

    public function processCheckout(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
        ]);

        // 1. Create the base order
        $reference = 'GP-' . strtoupper(Str::random(10));

        $order = Order::create([
            'reference' => $reference,
            'customer_name' => $this->name,
            'customer_email' => $this->email,
            'customer_phone' => $this->phone,
            'shipping_address' => $this->address,
            'total_amount' => $this->total,
            'status' => 'pending',
            'payment_method' => 'paystack',
        ]);

        // 2. Attact the order items (Crucial for vendor splitting later)
        foreach ($this->cartItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product']->id,
                'vendor_id' => $item['product']->vendor_id,
                'quantity' => $item['quantity'],
                'unit_price' => $item['product']->price,
            ]);
        }

        // 3. Initialize paystact payment
        try{
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withOptions([
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ])
                ->withToken(env('PAYSTACK_SECRET_KEY'))
                ->post('https://api.paystack.co/transaction/initialize', [
                    'amount' => (int) ($this->total * 100), 
                    'email' => $this->email,
                    'reference' => $reference,
                    'callback_url' => route('payment.callback'),
                ]);

            if ($response->successful()) {
                // Redirect the user to the paystack payment page
                $this->redirect($response->json('data.authorisation_url'));
            } else {
                dd([
                    'status' => $response->status(),
                    'error' => $response->json(),
                    'key_used' => env('PAYSTACK_SECRET_KEY') ? 'Key is present' : 'Key is missing',
                ]);
                session()->flash('error', 'Could not connect to payment gateway, Please try again.');
            }
        } catch (\Exception $e) {
            dd('SYSTEM CRASH BEFORE REACHING PAYSTACK:'.$e->getMessage());
        }
    }
}; ?>

<div>
    <x-layouts.storefront>
        <div class="max-w-3xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-8">Checkout</h1>

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                @if (session()->has('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                <form wire:submit="processCheckout" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Full Name</label>
                            <input type="text" id="name" wire:model="name" required class="px-4 py-2 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
                            <input type="email" id="email" wire:model="email" required class="px-4 py-2 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone Number</label>
                        <input type="text" id="phone" wire:model="phone" required class="px-4 py-2 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Delivery Address</label>
                        <textarea id="address" wire:model="address" required class="px-4 py-2 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6 mt-6">
                        <div class="flex justify-between item-center mb-6">
                            <span class="text-lg font-medium text-gray-900 dark:text-white">Total Amount:</span>
                            <span class="text-2xl font-bold text-gray-900 dark:text-white">₦{{ number_format($total, 2) }}</span>
                        </div>
                    </div>

                    <button type="submit" class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition">Pay with Paystack</button>
                </form>
            </div>
        </div>
    </x-layouts.storefront>
</div>
