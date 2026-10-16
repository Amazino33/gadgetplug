<?php

namespace App\Http\Controllers\Payment;

use App\Actions\Payment\ConfirmPaystackPaymentAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class PaystackCallbackController extends Controller
{
    /**
     * The customer's browser returning from Paystack. The webhook may already
     * have confirmed the order; the action is idempotent, so either order of
     * arrival ends the same.
     */
    public function __invoke(Request $request, ConfirmPaystackPaymentAction $confirm)
    {
        $reference = $request->query('reference');

        if (! $reference) {
            abort(400, 'No reference supplied');
        }

        $result = $confirm->execute((string) $reference);

        switch ($result) {
            case ConfirmPaystackPaymentAction::PAID:
            case ConfirmPaystackPaymentAction::ALREADY:
                Session::forget('cart');
                session()->put('payment_success', (string) $reference);

                return redirect()->route('checkout');

            case ConfirmPaystackPaymentAction::FAILED_STOCK:
                Session::forget('cart');

                return redirect()->route('home')->with('warning', 'Payment successful, but an item sold out during checkout. Our team will contact you to resolve this.');

            case ConfirmPaystackPaymentAction::NOT_FOUND:
                abort(404);

            default: // UNVERIFIED, MISMATCH
                return redirect()->route('cart')->with('error', 'Payment failed or was cancelled.');
        }
    }
}
