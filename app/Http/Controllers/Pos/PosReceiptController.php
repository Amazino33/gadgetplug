<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Models\VendorReceiptSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renders a sale as a standalone 80mm receipt document.
 *
 * Separate from the POS modal on purpose: printing out of the modal meant
 * fighting the app's own stylesheet with visibility and position hacks, which
 * clipped anything longer than one page. A dedicated document also puts the
 * vendor's receipt settings, the cashier's name and the store's address on the
 * paper — none of which the browser-side modal had access to.
 */
class PosReceiptController extends Controller
{
    public function show(Request $request, PosSale $sale): View
    {
        $user = $request->user();

        // A receipt names a customer and a cashier, so it is never public here.
        // The QR-linked copy for customers will be a separate, token-addressed
        // page that omits those details.
        abort_unless($user && $sale->vendor && $sale->vendor->canAccess($user), 403);

        $sale->loadMissing(['items', 'payments', 'cashier', 'customer', 'vendor']);

        $vendor   = $sale->vendor;
        $settings = VendorReceiptSetting::forVendor($vendor);

        return view('pos.receipt', [
            'sale'         => $sale,
            'vendor'       => $vendor,
            'settings'     => $settings,
            'soldAt'       => $sale->completed_at ?? $sale->created_at,
            'cashierName'  => $sale->cashier?->name,
            'customerName' => $sale->customer?->name,
            'payments'     => $sale->payments,
            'items'        => $sale->items->map(fn ($item) => [
                'name'       => $item->product_name,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'total'      => $item->total,
            ]),
            // VAT is a vendor setting; the old receipt hardcoded "VAT (7.5%)"
            // and would have lied on any store using a different rate.
            'vatEnabled'   => (bool) ($vendor->pos_vat_enabled ?? true),
            'vatRate'      => $vendor->pos_vat_rate ?? 7.5,
            'logoUrl'      => $vendor->logo ? asset('storage/' . $vendor->logo) : null,
            // ?print=1 makes the page print itself — that is how the POS opens it
            'autoPrint'    => $request->boolean('print'),
            // Rendered at generous size: a thermal head turns a small QR to mush.
            //
            // Never allowed to take the receipt down with it. If the QR encoder
            // fails, the cashier still needs paper in the customer's hand — and
            // the POS treats any error from this endpoint as "print the old
            // modal instead", so an exception here costs the whole layout, not
            // just the code.
            'qrSvg'        => $this->qrOrNull($settings, $sale),
        ]);
    }

    private function qrOrNull(\App\Models\VendorReceiptSetting $settings, PosSale $sale): ?string
    {
        if (! ($settings->show_qr ?? true) || ! $sale->public_token) {
            return null;
        }

        try {
            return \App\Services\QrCode::svg($sale->publicUrl(), 240);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Receipt QR could not be generated', [
                'sale_id' => $sale->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
