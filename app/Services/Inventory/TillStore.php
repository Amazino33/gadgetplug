<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\TillBranchUnclear;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Illuminate\Support\Collection;

// Which branch a POS terminal is standing in.
//
// The panel's ActiveStore cannot answer this: the till authenticates as a
// cashier over a token, with no Filament tenant and no panel session, so
// there is no active store to read. The branch is chosen when the till signs
// in and written onto that token as a `pos-store:{id}` ability; a cashier who
// works in one branch is signed in to it without being asked.
//
// Anything less certain is refused (TillBranchUnclear), never guessed. This
// used to fall back to the vendor's default store, and on 26/09/2026 that
// fallback checked a day of Zeelink Phones sales against a branch holding
// none of the phones — every one of them was refused as "Insufficient stock".
class TillStore
{
    public const ABILITY_PREFIX = 'pos-store:';

    public static function ability(int $storeId): string
    {
        return self::ABILITY_PREFIX.$storeId;
    }

    /**
     * The branch this till is signed in to.
     *
     * @throws TillBranchUnclear
     */
    public static function resolve(User $cashier, int $vendorId): int
    {
        $stores     = Store::where('vendor_id', $vendorId)->get();
        $accessible = self::accessible($cashier, $vendorId);

        $bound = self::boundStore($cashier, $stores);

        if ($bound !== null) {
            if (! $accessible->contains('id', $bound)) {
                throw TillBranchUnclear::noLongerAssigned();
            }

            return $bound;
        }

        return match ($accessible->count()) {
            1       => (int) $accessible->first()->id,
            0       => throw TillBranchUnclear::noBranch(),
            default => throw TillBranchUnclear::severalBranches(),
        };
    }

    /**
     * The branch a sale belongs to.
     *
     * A sale rung on this till carries the branch it was rung in, and that is
     * where it happened even if the till has since been signed in elsewhere —
     * provided the person syncing it works there. A sale queued before the till
     * recorded its branch names none, and goes to the till's own branch.
     *
     * @throws TillBranchUnclear
     */
    public static function forSale(User $cashier, int $vendorId, ?int $rungAt): int
    {
        if ($rungAt === null) {
            return self::resolve($cashier, $vendorId);
        }

        if (self::accessible($cashier, $vendorId)->contains('id', $rungAt)) {
            return $rungAt;
        }

        throw TillBranchUnclear::notYourBranch(
            Store::where('vendor_id', $vendorId)->whereKey($rungAt)->value('name') ?? 'another branch'
        );
    }

    /** @return Collection<int, Store> */
    public static function accessible(User $cashier, int $vendorId): Collection
    {
        $vendor = Vendor::find($vendorId);

        return $vendor ? ActiveStore::accessibleFor($vendor, $cashier) : collect();
    }

    /**
     * The branch written onto the till's token at sign-in, if exactly one.
     *
     * Asked through can() rather than by reading the abilities list, so it
     * behaves the same for a real token and for the one Sanctum::actingAs()
     * builds in tests. A wildcard token can do everything and therefore names
     * no branch in particular.
     *
     * @param  Collection<int, Store>  $stores
     */
    private static function boundStore(User $cashier, Collection $stores): ?int
    {
        $token = $cashier->currentAccessToken();

        if (! $token || $stores->isEmpty()) {
            return null;
        }

        $claimed = $stores->filter(fn (Store $store) => $token->can(self::ability($store->id)));

        return $claimed->count() === 1 ? (int) $claimed->first()->id : null;
    }
}
