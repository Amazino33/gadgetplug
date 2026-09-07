<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Liking, saving and sharing a post — in one place, so the rules hold wherever
 * they are called from.
 *
 * The UI gates saving behind login, but the gate is repeated here: a UI check
 * is a courtesy to the user, not a control, and this is the only thing standing
 * between a crafted request and a save with nobody attached to it.
 *
 * Saving is deliberately a thin wrapper over the existing wishlists table
 * rather than a third interaction type. That table is already login-gated and
 * already has a page reading it; a parallel one would mean two answers to
 * "did I save this".
 */
class ProductInteractions
{
    /**
     * Turn a like on or off, and return whether it is now on.
     *
     * Guests are allowed — a like is cheap, reversible and shows nothing about
     * a person, so demanding an account for it costs more engagement than it
     * protects.
     */
    public function toggleLike(Product $product, ?User $user = null, ?string $deviceToken = null): bool
    {
        [$userId, $token] = $this->identify($user, $deviceToken);

        if (! $userId && ! $token) {
            // No identity at all: without one there is nothing to attach the
            // like to, and no way to turn it off again.
            throw new RuntimeException('No device token or user to attribute this like to.');
        }

        return DB::transaction(function () use ($product, $userId, $token) {
            $existing = ProductInteraction::query()
                ->where('product_id', $product->id)
                ->likes()
                ->for($userId, $token)
                ->first();

            if ($existing) {
                $existing->delete();
                $this->adjustCount($product, 'like_count', -1);

                return false;
            }

            try {
                ProductInteraction::create([
                    'product_id'   => $product->id,
                    'user_id'      => $userId,
                    'device_token' => $userId ? null : $token,
                    'type'         => ProductInteraction::TYPE_LIKE,
                    // What the unique index bites on. Shares leave it null so
                    // the same product can be shared more than once.
                    'unique_key'   => ProductInteraction::TYPE_LIKE,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two taps raced. The other one won and the like is on, which
                // is the answer either way — not an error to show anybody.
                return true;
            }

            $this->adjustCount($product, 'like_count', 1);

            return true;
        });
    }

    /**
     * Save to the wishlist. Signed-in only, enforced here and not merely in the
     * UI that hides the button.
     */
    public function save(Product $product, ?User $user): bool
    {
        if (! $user) {
            throw new RuntimeException('Saving needs an account.');
        }

        Wishlist::firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id]);

        return true;
    }

    public function unsave(Product $product, ?User $user): bool
    {
        if (! $user) {
            throw new RuntimeException('Saving needs an account.');
        }

        Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->delete();

        return false;
    }

    public function hasSaved(Product $product, ?User $user): bool
    {
        return $user
            ? Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists()
            : false;
    }

    /**
     * Record that a post was shared.
     *
     * Append-only: each share is something that happened, so there is no unique
     * index to fall foul of and nothing to toggle. Sharing the same product
     * twice is two shares.
     */
    public function logShare(Product $product, ?User $user = null, ?string $deviceToken = null): void
    {
        [$userId, $token] = $this->identify($user, $deviceToken);

        ProductInteraction::create([
            'product_id'   => $product->id,
            'user_id'      => $userId,
            'device_token' => $userId ? null : $token,
            'type'         => ProductInteraction::TYPE_SHARE,
            'unique_key'   => null,
        ]);

        $this->adjustCount($product, 'share_count', 1);
    }

    /** Whether this viewer has already liked these products, keyed by id. */
    public function likedProductIds(array $productIds, ?User $user, ?string $deviceToken): array
    {
        if ($productIds === []) {
            return [];
        }

        [$userId, $token] = $this->identify($user, $deviceToken);

        if (! $userId && ! $token) {
            return [];
        }

        return ProductInteraction::query()
            ->whereIn('product_id', $productIds)
            ->likes()
            ->for($userId, $token)
            ->pluck('product_id')
            ->all();
    }

    /**
     * Hand a guest's likes to the account they just signed into.
     *
     * Reassigned rather than copied, so a like survives signing in instead of
     * silently reverting to unliked. A product the account had already liked on
     * another device would collide on the unique index, so those rows are
     * dropped instead — the like already exists, and one is the correct number.
     */
    public function mergeDeviceInto(User $user, ?string $deviceToken): int
    {
        if (! $deviceToken) {
            return 0;
        }

        return DB::transaction(function () use ($user, $deviceToken) {
            $rows = ProductInteraction::query()
                ->where('device_token', $deviceToken)
                ->whereNull('user_id')
                ->likes()
                ->get();

            $merged = 0;

            foreach ($rows as $row) {
                $alreadyMine = ProductInteraction::query()
                    ->where('product_id', $row->product_id)
                    ->where('user_id', $user->id)
                    ->likes()
                    ->exists();

                if ($alreadyMine) {
                    // The count already includes this product once. Deleting
                    // the orphan must not decrement it.
                    $row->delete();

                    continue;
                }

                $row->update([
                    'user_id'      => $user->id,
                    'device_token' => null,
                    'unique_key'   => ProductInteraction::TYPE_LIKE,
                ]);
                $merged++;
            }

            return $merged;
        });
    }

    /**
     * @return array{0: ?int, 1: ?string} user id and device token, in that order
     */
    private function identify(?User $user, ?string $deviceToken): array
    {
        $user ??= auth()->user();
        $deviceToken ??= EnsureDeviceToken::current();

        return [$user?->id, $deviceToken];
    }

    /**
     * Move a denormalised counter.
     *
     * A raw increment rather than a read-then-write: two people liking the same
     * post at once would otherwise both read the same number and both write it
     * back, losing one. Floored at zero so a counter that has drifted below
     * cannot go negative on screen — the reconcile command is what actually
     * repairs drift.
     */
    private function adjustCount(Product $product, string $column, int $delta): void
    {
        if ($delta > 0) {
            Product::whereKey($product->id)->increment($column, $delta);
        } else {
            Product::whereKey($product->id)->where($column, '>', 0)->decrement($column, abs($delta));
        }
    }
}
