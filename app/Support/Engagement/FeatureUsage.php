<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\Features\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * "Has this person used this feature, and since when?" — cached.
 *
 * The answer for every feature is computed together and cached per person per
 * vendor, so a page render costs one cache read. The cache key carries a
 * per-vendor version that any save of a usage source bumps (see
 * EngagementServiceProvider), so recording a debt clears the debt tip at once
 * rather than ten minutes later. The TTL is the backstop for sources with no
 * model to listen to (photos, staff, branches).
 */
class FeatureUsage
{
    public const TTL_SECONDS = 600;

    /** @var array<string, bool> */
    private array $ownerOrAdmin = [];

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

    /** @return array<string, string|null> feature key => ISO first use */
    public function map(User $user, Vendor $vendor): array
    {
        $key = sprintf('engagement:usage:%d:%d:%s', $vendor->id, $user->id, static::version($vendor->id));

        return Cache::remember($key, self::TTL_SECONDS, function () use ($user, $vendor): array {
            $ownerOrAdmin = $this->isOwnerOrAdmin($user, $vendor);
            $map = [];

            foreach ($this->registry->all() as $featureKey => $feature) {
                $at = $feature->isBusinessWideFor($ownerOrAdmin)
                    ? $feature->firstUsedAt($vendor->id)
                    : $feature->firstUsedAt($vendor->id, $user->id);

                $map[$featureKey] = $at?->toIso8601String();
            }

            return $map;
        });
    }

    public function isOwnerOrAdmin(User $user, Vendor $vendor): bool
    {
        return $this->ownerOrAdmin[$user->id.':'.$vendor->id]
            ??= $vendor->isOwner($user) || $user->isSuperAdmin();
    }

    /** Invalidate every cached usage answer for a vendor. */
    public static function bump(int $vendorId): void
    {
        Cache::forever(static::versionKey($vendorId), (string) hrtime(true));
    }

    private static function version(int $vendorId): string
    {
        return (string) Cache::get(static::versionKey($vendorId), '0');
    }

    private static function versionKey(int $vendorId): string
    {
        return 'engagement:usage-version:'.$vendorId;
    }
}
