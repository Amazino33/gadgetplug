<?php 
namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Actions\Inventory\AdjustStockAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Exception;

class PaystackCallbackController extends Controller
{
    /**
     * Handle the incoming Paystack webhook/callback.
     */
    public function __invoke(Request $request, AdjustStockAction $adjustStock)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            abort(400, 'No reference supplied');
        }

        // Verify the transaction with Paystack
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        // If verification fails, redirect immediately
        if (!$response->successful() || $response->json('data.status') !== 'success') {
            return redirect()->route('cart')->with('error', 'Payment failed or was cancelled.');
        }

        // Find the order with its items to prevent N+1 queries
        $order = Order::with('items')->where('reference', $reference)->firstOrFail();

        // if it is already paid (e.g., webhook and redirect hit at the same time), just return success
        if ($order->status !== 'pending') {
            Session::forget('cart');
            session()->put('payment_success', $order->reference);
            return redirect()->route('checkout');
        }

        try {
            // 1. Deduct stock safely using our "Bouncer" Action class
            foreach ($order->items as $item) {
                $adjustStock->execute(
                    productId: $item->product_id,
                    quantityChanged: -$item->quantity, // Negative for sale
                    transactionType: 'online_sale',
                    userId: null,
                    reference: $order->reference,
                    description: 'Automated deduction from paystack checkout.'
                );
            }

            // 2. Only update status to 'paid' If the stock deduction succeeds
            $order->update(['status' => 'paid']);

            // 3. Clear the cart
            Session::forget('cart');

            session()->put('payment_success', $order->reference);
            return redirect()->route('checkout');
        } catch (Exception $e) {
            // 4. Race condition caught
            // Someone bought the last item while this user was typing their card details
            Log::error("Stock deduction failed after payment for order {$order->id}: " . $e->getMessage());

            $order->update(['status' => 'paid_but_failed_stock']);
            Session::forget('cart');

            return redirect()->route('home')->with('warning', 'Payment successful, but an item sold out during checkout. Our team will contact you to resolve this.');
        }
    }
}