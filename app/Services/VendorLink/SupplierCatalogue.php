<?php

declare(strict_types=1);

namespace App\Services\VendorLink;

use App\Models\Product;
use App\Models\SupplierLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place in the application that reads across the tenant line.
 *
 * Deliberately one place. Filament implements tenancy by registering a GLOBAL
 * SCOPE on the model (BelongsToTenant::registerGlobalScope), so while the vendor
 * panel is booted every Product query anywhere is silently filtered to the
 * active tenant — a supplier's catalogue read without dropping it comes back
 * empty, and empty is the shape of "nothing to publish" rather than an error.
 * That is a failure that looks like success, which is the worst kind, so the
 * drop lives here where it can be read once and tested once rather than
 * repeated at every call site.
 *
 * The scope is dropped by the name the panel itself gives it, never a hardcoded
 * string. Narrow by construction: the query is pinned to the one supplier id an
 * active link names, so dropping the scope cannot widen it to anything else.
 */
class SupplierCatalogue
{
    /** The supplier's live catalogue, as this link is allowed to see it. */
    public static function query(SupplierLink $link): Builder
    {
        return self::unscoped()
            ->where('vendor_id', $link->supplier_vendor_id)
            ->where('status', 'published');
    }

    /**
     * One product from the supplier's catalogue, or null.
     *
     * Null covers both "no such product" and "not this supplier's" on purpose:
     * a caller must not be able to tell the difference, or the id becomes a way
     * to probe another vendor's catalogue.
     */
    public static function find(SupplierLink $link, int $productId): ?Product
    {
        return self::query($link)->find($productId);
    }

    private static function unscoped(): Builder
    {
        $scope = function_exists('filament')
            ? filament()->getCurrentPanel()?->getTenancyScopeName()
            : null;

        return Product::query()
            ->when($scope, fn (Builder $q) => $q->withoutGlobalScope($scope));
    }
}
