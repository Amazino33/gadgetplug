<?php

namespace App\Http\Controllers\Payment;

use App\Actions\Payment\ConfirmPaystackPaymentAction;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaystackClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackClient $paystack, ConfirmPaystackPaymentAction $confirm): JsonResponse
    {
        // The raw body, not the parsed one: the signature is over the exact bytes.
        $raw = $request->getContent();

        if (! $paystack->validSignature($raw, $request->header('x-paystack-signature'))) {
            Log::warning('Paystack webhook rejected: bad signature.', ['ip' => $request->ip()]);

            return response()->json(['status' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        $event   = is_array($payload) ? ($payload['event'] ?? null) : null;

        if ($event !== 'charge.success') {
            Log::info('Paystack webhook ignored.', ['event' => $event]);

            return response()->json(['status' => 'ignored']);
        }

        $reference = $payload['data']['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            Log::warning('Paystack charge.success webhook without a reference.');

            return response()->json(['status' => 'ignored']);
        }

        $result = $confirm->execute($reference);

        // Paystack retries anything that is not a 2xx. Only a failure to reach
        // Paystack's verify endpoint is worth a retry; a mismatch or an
        // unknown reference will not improve, so those are acknowledged.
        if ($result === ConfirmPaystackPaymentAction::UNVERIFIED) {
            return response()->json(['status' => 'unverified'], 503);
        }

        return response()->json(['status' => $result]);
    }
}
