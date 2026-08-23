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
            'qrDataUri'    => ($settings->show_qr ?? true) && $sale->public_token
                ? \App\Services\QrCode::svgDataUri($sale->publicUrl(), 240)
                : null,
        ]);
    }
}
