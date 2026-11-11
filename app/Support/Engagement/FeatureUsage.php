<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\Features\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * "Has this person used this feature, and since when?", cached.
 *
 * One cache entry per feature, per vendor, per scope (the business, or one
 * person). A first-use date never changes once set, so a cached date is kept
 * for a long time and nothing ever clears it. Only "not used yet" can go
 * stale, so only that answer is short-lived, and only that answer is cleared
 * when a row that counts as use is saved (see sourceSaved()).
 *
 * A page render reads every entry it needs in one Cache::many() call.
 */
class FeatureUsage
{
    /** Cached value meaning "checked, and not used yet". */
    public const NEVER = 'never';

    /** How long "not used yet" is trusted when nothing clears it. */
    public const NEVER_TTL_SECONDS = 600;

    /** A first-use date never changes; this TTL is only housekeeping. */
    public const USED_TTL_SECONDS = 60 * 60 * 24 * 30;

    /** @var array<string, bool> */
    private array $ownerOrAdmin = [];

    /**
     * Cache keys this request knows need no clearing: they hold a date
     * (which never changes) or hold nothing at all. A save that only touches
     * these keys does no cache work, which is what keeps a 500-sale offline
     * sync from reading the cache 500 times. map() takes a key back out when
     * it writes "not used yet" into it.
     *
     * @var array<string, true>
     */
    private array $settled = [];

    /**
     * Keys with a recheck already waiting for a commit, so saves inside one
     * transaction queue one recheck between them.
     *
     * @var array<string, true>
     */
    private array $queued = [];

    public function __construct(private readonly FeatureRegistry $registry) {}

    public function firstUsedAt(Feature $feature, User $user, Vendor $vendor): ?CarbonImmutable
    {
        $value = $this->map($user, $vendor)[$feature->key()] ?? null;

        return $value ? CarbonImmutable::parse($value) : null;
    }

    public function hasUsed(Feature $feature, User $user, Vendor $vendor): bool
    {
        return $this->firstUsedAt($feature, $user, $vendor) !== null;
    }

    /** True when the answer for this person is really the whole business's. */
    public function isBusinessWide(Feature $feature, User $user, Vendor $vendor): bool
    {
        return $feature->isBusinessWideFor($this->isOwnerOrAdmin($user, $vendor));
    }

    /** @return array<string, string|null> feature key => ISO first use, null if not used */
    public function map(User $user, Vendor $vendor): array
    {
        $ownerOrAdmin = $this->isOwnerOrAdmin($user, $vendor);
        $keys = [];

        foreach ($this->registry->all() as $featureKey => $feature) {
            $userId = $feature->isBusinessWideFor($ownerOrAdmin) ? null : $user->id;
            $keys[$featureKey] = static::cacheKey($featureKey, $vendor->id, $userId);
        }

        $cached = Cache::many(array_values($keys));
        $map = [];

        foreach ($keys as $featureKey => $cacheKey) {
            $value = $cached[$cacheKey] ?? null;

            if ($value === null) {
                $feature = $this->registry->find($featureKey);
                $userId = $feature->isBusinessWideFor($ownerOrAdmin) ? null : $user->id;
                $at = $feature->firstUsedAt($vendor->id, $userId);
                $value = $at?->toIso8601String() ?? self::NEVER;

                Cache::put($cacheKey, $value, $value === self::NEVER ? self::NEVER_TTL_SECONDS : self::USED_TTL_SECONDS);
            }

            if ($value === self::NEVER) {
                unset($this->settled[$cacheKey]);
            } else {
                $this->settled[$cacheKey] = true;
            }

            $map[$featureKey] = $value === self::NEVER ? null : $value;
        }

        return $map;
    }

    /**
     * Cache keys a saved row could make stale: the business's, and each
     * person the row names. Empty when this request already knows none of
     * them holds "not used yet", which is the common case once a business is
     * up and running, and for every save after the first in a bulk sync.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    public function keysToRecheck(Feature $feature, int $vendorId, array $userIds): array
    {
        $keys = [static::cacheKey($feature->key(), $vendorId, null)];

        if ($feature->supportsUserLevel()) {
            foreach (array_unique($userIds) as $userId) {
                $keys[] = static::cacheKey($feature->key(), $vendorId, $userId);
            }
        }

        $keys = array_values(array_filter(
            $keys,
            fn (string $key) => ! isset($this->settled[$key]) && ! isset($this->queued[$key]),
        ));

        foreach ($keys as $key) {
            $this->queued[$key] = true;
        }

        return $keys;
    }

    /**
     * A row that counts as use was saved. Clears any cached "not used yet"
     * among $keys; leaves dates alone (they cannot change). Run after commit.
     *
     * @param  array<int, string>  $keys  from keysToRecheck()
     */
    public function recheck(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->queued[$key]);
        }

        foreach (Cache::many($keys) as $key => $value) {
            if ($value === self::NEVER) {
                Cache::forget($key);
            }

            // Now a date or nothing: either way, nothing more to clear here.
            $this->settled[$key] = true;
        }
    }

    public function isOwnerOrAdmin(User $user, Vendor $vendor): bool
    {
        return $this->ownerOrAdmin[$user->id.':'.$vendor->id]
            ??= $vendor->isOwner($user) || $user->isSuperAdmin();
    }

    public static function cacheKey(string $featureKey, int $vendorId, ?int $userId): string
    {
        return sprintf('engagement:first-use:%s:%d:%s', $featureKey, $vendorId, $userId ?? 'business');
    }
}
