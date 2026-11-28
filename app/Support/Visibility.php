<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Illuminate\Support\Collection;

/**
 * What a member of staff may SEE, chosen per role on the Roles screen under
 * "What they can see". Being able to do a job (sell, count, receive stock)
 * does not mean seeing what the business earns or is worth.
 *
 * The owner and the platform's super admin see everything (hasVendorPermission
 * says yes to both). Everyone else sees a figure only when one of their roles
 * holds its permission.
 */
final class Visibility
{
    /** Cost prices of products, and anything worked out from them per product (margin, markup). */
    public const COST = 'view_cost_price';

    /** What the business makes: profit, margin, cost of goods sold, on the dashboard, reports and charts. */
    public const PROFIT = 'view_profit';

    /** How much was sold, in naira: revenue on the dashboard, reports and charts. */
    public const SALES_TOTALS = 'view_sales_totals';

    /** What the stock is worth, at selling price or at cost. */
    public const STOCK_VALUE = 'view_stock_value';

    /** Figures of branches this person does not work in. */
    public const OTHER_BRANCHES = 'view_other_branches';

    /** In the order the Roles screen lists them, with what each means there. */
    public const LABELS = [
        self::COST           => ['Cost prices', 'What each product cost the shop, and its margin.'],
        self::PROFIT         => ['Profit', 'What the business makes: profit and margin on the dashboard, reports and charts.'],
        self::SALES_TOTALS   => ['Sales totals', 'How much the shop sold, in naira, on the dashboard and reports. Selling at the till does not need it.'],
        self::STOCK_VALUE    => ['Stock value', 'What the stock is worth, at selling price and at cost.'],
        self::OTHER_BRANCHES => ['Other branches', 'Figures of branches they do not work in, and "All branches" totals.'],
    ];

    public static function cost(?User $user = null, ?Vendor $vendor = null): bool
    {
        return self::sees(self::COST, $user, $vendor);
    }

    public static function profit(?User $user = null, ?Vendor $vendor = null): bool
    {
        return self::sees(self::PROFIT, $user, $vendor);
    }

    public static function salesTotals(?User $user = null, ?Vendor $vendor = null): bool
    {
        return self::sees(self::SALES_TOTALS, $user, $vendor);
    }

    public static function stockValue(?User $user = null, ?Vendor $vendor = null): bool
    {
        return self::sees(self::STOCK_VALUE, $user, $vendor);
    }

    public static function otherBranches(?User $user = null, ?Vendor $vendor = null): bool
    {
        return self::sees(self::OTHER_BRANCHES, $user, $vendor);
    }

    /**
     * The branches whose figures this person may see: all of them with "Other
     * branches", otherwise those they work in.
     *
     * @return Collection<int, Store>
     */
    public static function reportStores(Vendor $vendor, User $user): Collection
    {
        $mine = ActiveStore::accessibleFor($vendor, $user);

        if (self::otherBranches($user, $vendor)) {
            return Store::query()->where('vendor_id', $vendor->id)->orderByDesc('is_default')->orderBy('name')->get();
        }

        return $mine;
    }

    /**
     * Whether "All branches" may be shown as one total: only when that total
     * is made of branches this person may see, every one of them.
     */
    public static function allBranches(Vendor $vendor, User $user): bool
    {
        if (self::otherBranches($user, $vendor)) {
            return true;
        }

        return ActiveStore::accessibleFor($vendor, $user)->count()
            >= Store::query()->where('vendor_id', $vendor->id)->count();
    }

    private static function sees(string $permission, ?User $user, ?Vendor $vendor): bool
    {
        $user ??= auth()->user();
        $vendor ??= filament()->getTenant();

        if (! $user instanceof User || ! $vendor instanceof Vendor) {
            return false;
        }

        return $user->hasVendorPermission($vendor->id, $permission);
    }
}
