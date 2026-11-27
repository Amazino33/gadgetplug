<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\Products\ProductCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The whole Products list for the branch, for the Products page to keep in the
 * browser (see ProductCatalogue). With ?v= the page asks "has anything
 * changed since this version?" and gets {same: true} when nothing has.
 *
 * The same people as the Products screen: anyone who may see the shop's
 * products, never anyone else's shop. Cost prices only for those allowed.
 */
class ProductCatalogueController extends Controller
{
    public function __invoke(Request $request, Vendor $vendor, ProductCatalogue $catalogue): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $user->canAccessTenant($vendor), 404);
        abort_unless($user->isSuperAdmin() || $user->hasVendorPermission($vendor->id, 'view_any_products'), 403);
        abort_if($vendor->isDashboardBlocked() && ! $user->isSuperAdmin(), 403);

        $storeId  = ActiveStore::get($vendor, $user)?->id;
        $withCost = $user->isSuperAdmin() || $vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, 'view_cost_price');

        $version = $catalogue->version($vendor, $storeId);
        if ($request->query('v') === $version) {
            return response()->json(['same' => true, 'v' => $version]);
        }

        return response()->json($catalogue->build($vendor, $storeId, $withCost))
            ->header('Cache-Control', 'private, no-store');
    }
}
