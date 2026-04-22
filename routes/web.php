<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.product-catalog')->name('home');
Volt::route('/product/{product:slug}', 'pages.product-detail')->name('product.show');
Volt::route('/cart', 'pages.cart')->name('cart');
Volt::route('/checkout', 'checkout')->name('checkout');

Route::get('/payment/callback', function (Request $request) {
    $reference = $request->query('reference');

    if (!$reference) {
        abort(400, 'No reference supplied');
    }

    // Verify the transaction with Paystack
    $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
        ->get("http://api.paystack.co/transaction/verify/{$reference}");

    if ($response->successful() && $response->json('data.status') === 'success') {
        // Find the order and mark it as paid
        $order = Order::where('reference', $reference)->firstOrFail();

        if ($order->status === 'pending') {
            $order->update(['status' => 'paid']);

            // Deduct stock from product
            foreach ($order->items as $item) {
                $item->product->decrement('stock_quantity', $item->quantity);
            }
        }

        // Clear the cart
        Session::forget('cart');
        
        return redirect()->route('home')->with('success', 'Payment successful! Your order is being processed.');
    }

    return redirect()->route('cart')->with('error', 'Payment failed or was cancelled.');
})->name('payment.callback');


Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
