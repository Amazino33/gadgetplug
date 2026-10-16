<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// The one place that talks to Paystack's transaction API for confirming a
// payment. The callback and the webhook both route through here (via
// ConfirmPaystackPaymentAction), so what counts as "verified" is defined once.
class PaystackClient
{
    /**
     * The transaction as Paystack reports it, or null when it could not be
     * fetched (network failure, non-2xx, unparseable body).
     *
     * Null means "unknown", not "unpaid": a caller must not treat it as proof
     * the customer did not pay.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $reference): ?array
    {
        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->timeout(20)
                ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference));
        } catch (Throwable $e) {
            Log::warning('Paystack verify request failed.', ['reference' => $reference, 'exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            return null;
        }

        return $response->json('data');
    }

    /** Whether a webhook body was really signed by Paystack with our secret key. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '' || blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }
}
