<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Affiliate\AttributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AffiliateClickController extends Controller
{
    public function redirect(Request $request, string $code, AttributionService $attribution): RedirectResponse
    {
        $affiliate = $attribution->findActiveByCode($code);

        if (! $affiliate) {
            return redirect()->route('home');
        }

        $affiliate->clicks()->create([
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'landing_url' => $request->query('to'),
        ]);

        $attribution->rememberClick($affiliate);

        // ?to= a product slug lands the scan/click on that product page,
        // carrying the code via the cookie just set above — same
        // attribution, just a different landing URL than the homepage.
        $productSlug = $request->query('to');

        if ($productSlug) {
            $product = Product::where('slug', $productSlug)->first();

            if ($product) {
                return redirect()->route('product.show', ['product' => $product]);
            }
        }

        return redirect()->route('home');
    }
}
