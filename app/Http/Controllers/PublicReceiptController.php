<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PosCustomer;
use App\Models\PosSale;
use App\Models\VendorReceiptSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The customer's own copy of a receipt, opened by the QR on the paper.
 *
 * Deliberately unauthenticated — a customer walking out of the shop has no
 * account — so the URL's random token is the only thing standing between this
 * page and the public. Two consequences shape everything here:
 *
 *  - The customer's name and phone are never rendered. A printed QR can be
 *    photographed, forwarded, or left on a counter, and the person holding the
 *    paper is not necessarily the person who bought.
 *  - Nothing on the page can be used to enumerate other sales. It is addressed
 *    only by token, and no id or reference of any other sale appears.
 */
class PublicReceiptController extends Controller
{
    public function show(string $token): View
    {
        $sale = $this->resolve($token);

        return view('receipt.public', $this->payload($sale));
    }

    /** The same receipt as a downloadable PDF, for keeping. */
    public function pdf(string $token): Response
    {
        $sale = $this->resolve($token);

        $pdf = Pdf::loadView('receipt.public-pdf', $this->payload($sale))
            ->setPaper('a4', 'portrait');

        return $pdf->download('receipt-' . $sale->reference . '.pdf');
    }

    /**
     * Records that the customer claimed this sale on their loyalty card.
     *
     * Claiming is per sale, not per tap, so refreshing or forwarding the link
     * cannot inflate anyone's progress. Progress itself is read from the till's
     * own transaction count rather than a separate tally, so the number shown is
     * one the store can stand behind.
     */
    public function claimLoyalty(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $sale     = $this->resolve($token);
        $settings = VendorReceiptSetting::forVendor($sale->vendor);

        if (! $settings->loyalty_enabled) {
            return response()->json(['message' => 'Loyalty is not switched on for this store.'], 422);
        }

        // A walk-in has no record to count against — that is the nudge.
        if (! $sale->customer_id) {
            return response()->json([
                'claimed'  => false,
                'anonymous' => true,
                'message'  => 'Ask the cashier to save your phone number next time, and every visit starts counting.',
            ]);
        }

        if (! $sale->loyalty_claimed_at) {
            $sale->forceFill(['loyalty_claimed_at' => now()])->save();
        }

        $customer = PosCustomer::find($sale->customer_id);
        $visits   = (int) ($customer?->total_transactions ?? 0);
        $goal     = max(1, (int) $settings->loyalty_goal);
        $position = $visits % $goal;
        $toGo     = $position === 0 && $visits > 0 ? 0 : $goal - $position;

        return response()->json([
            'claimed'  => true,
            'visits'   => $visits,
            'goal'     => $goal,
            'position' => $position === 0 && $visits > 0 ? $goal : $position,
            'to_go'    => $toGo,
            'reward'   => $settings->loyalty_reward_text ?: 'a reward from us',
            'earned'   => $toGo === 0,
        ]);
    }

    private function resolve(string $token): PosSale
    {
        // A voided sale is not a receipt anyone should be shown as valid.
        return PosSale::with(['items', 'payments', 'vendor'])
            ->where('public_token', $token)
            ->where('status', '!=', 'voided')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(PosSale $sale): array
    {
        $vendor   = $sale->vendor;
        $settings = VendorReceiptSetting::forVendor($vendor);

        return [
            'sale'     => $sale,
            'vendor'   => $vendor,
            'settings' => $settings,
            'soldAt'   => $sale->completed_at ?? $sale->created_at,
            'items'    => $sale->items->map(fn ($item) => [
                'name'       => $item->product_name,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'total'      => $item->total,
            ]),
            'payments'   => $sale->payments,
            'vatEnabled' => (bool) ($vendor->pos_vat_enabled ?? true),
            'vatRate'    => $vendor->pos_vat_rate ?? 7.5,
            'bannerUrl'  => $settings->banner_image ? asset('storage/' . $settings->banner_image) : null,
            'logoUrl'    => $vendor->logo ? asset('storage/' . $vendor->logo) : null,
            // Never the customer's name or phone — see the class docblock.
        ];
    }
}
