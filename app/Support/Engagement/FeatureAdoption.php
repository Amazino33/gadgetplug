<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\TipEvent;
use App\Models\Vendor;
use App\Support\Engagement\Features\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which businesses use which features, across the platform: the admin Feature
 * Adoption report.
 *
 * Computed live from the registry (Feature::firstUseByVendor/firstUseByUser,
 * the same grouped MIN queries the per-tip funnel uses), with each feature's
 * raw answer cached for CACHE_SECONDS. Phase 0 recon chose this over a nightly
 * rollup: each feature is one or two indexed GROUP BY queries, about twenty
 * in all for a cold page, and a rollup would mean a new table, a cron entry on
 * a crontab shared with another app, and figures up to a day old for the
 * calls this report exists to prompt.
 *
 * Filters (plan, date joined) are applied to the cached answers, so changing
 * a filter never re-runs the heavy queries.
 */
class FeatureAdoption
{
    public const CACHE_SECONDS = 600;

    public const PLAN_PAID = 'paid';

    public const PLAN_FREE = 'free';

    public const PLAN_ALL = 'all';

    private const CACHE_PREFIX = 'engagement:adoption:';

    public function __construct(private readonly FeatureRegistry $registry) {}

    /**
     * One row per registered feature.
     *
     * @param  array{plan?: string|null, joined_from?: string|null, joined_until?: string|null}  $filters
     * @return list<array{key: string, title: string, vendors_using: int, vendors_total: int, percent: int, users_using: int|null, business_wide: bool, helpful: array, learn_taps: int}>
     */
    public function summary(array $filters = []): array
    {
        $population = $this->population($filters);
        $total = count($population);
        $titles = DB::table('app_features')->pluck('title', 'key');
        // "Show me" taps on the Learn page: interest, next to actual use.
        $taps = DB::table('tip_events')
            ->whereNull('tip_id')
            ->where('event', TipEvent::CLICKED)
            ->groupBy('feature_key')
            ->selectRaw('feature_key, COUNT(*) as taps')
            ->pluck('taps', 'feature_key');
        $rows = [];

        foreach ($this->registry->all() as $key => $feature) {
            $using = count(array_intersect_key($this->firstUseByVendor($feature), $population));
            // People who used it themselves, counted once per business they
            // did it at. Null where the tables don't record who did it.
            $users = $feature->supportsUserLevel()
                ? array_sum(array_map('count', array_intersect_key($this->usersByVendor($feature), $population)))
                : null;

            $rows[] = [
                'key' => $key,
                'title' => (string) ($titles[$key] ?? $feature->defaultTitle()),
                'vendors_using' => $using,
                'vendors_total' => $total,
                'percent' => $total > 0 ? (int) round(100 * $using / $total) : 0,
                'users_using' => $users,
                'business_wide' => ! $feature->supportsUserLevel(),
                'helpful' => TipFeedback::forFeature($key),
                'learn_taps' => (int) ($taps[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The businesses in the filtered population that have not used a feature
     * yet, newest first, with the owner to call.
     *
     * @param  array{plan?: string|null, joined_from?: string|null, joined_until?: string|null}  $filters
     * @return Collection<int, array{vendor_id: int, vendor: string, slug: string, owner: string|null, phone: string|null, joined_at: CarbonImmutable|null, plan: string}>
     */
    public function notUsing(string $featureKey, array $filters = []): Collection
    {
        $feature = $this->registry->find($featureKey);

        if (! $feature) {
            return collect();
        }

        $used = $this->firstUseByVendor($feature);

        return $this->populationQuery($filters)
            ->leftJoin('users', 'users.id', '=', 'vendors.user_id')
            ->orderByDesc('vendors.created_at')
            ->orderByDesc('vendors.id')
            ->get([
                'vendors.id', 'vendors.name', 'vendors.slug', 'vendors.plan', 'vendors.created_at',
                'vendors.whatsapp', 'users.name as owner_name', 'users.phone as owner_phone',
            ])
            ->reject(fn ($v) => isset($used[(int) $v->id]))
            ->map(fn ($v) => [
                'vendor_id' => (int) $v->id,
                'vendor' => (string) $v->name,
                'slug' => (string) $v->slug,
                'owner' => $v->owner_name,
                // The owner's own number first; the shop's WhatsApp if the
                // owner never gave one.
                'phone' => $v->owner_phone ?: ($v->whatsapp ?: null),
                'joined_at' => $v->created_at ? CarbonImmutable::parse($v->created_at) : null,
                'plan' => $v->plan === Vendor::PLAN_FREE ? 'Free' : 'Paid',
            ])
            ->values();
    }

    /** Clears every cached answer, so the next view is fully live. */
    public function refresh(): void
    {
        foreach ($this->registry->keys() as $key) {
            Cache::forget(self::CACHE_PREFIX.'vendors:'.$key);
            Cache::forget(self::CACHE_PREFIX.'users:'.$key);
        }
    }

    /**
     * vendor id => ISO date of first use, for every business that has used it.
     *
     * @return array<int, string>
     */
    public function firstUseByVendor(Feature $feature): array
    {
        return Cache::remember(self::CACHE_PREFIX.'vendors:'.$feature->key(), self::CACHE_SECONDS, fn (): array => array_map(
            fn ($at) => $at->toIso8601String(),
            $feature->firstUseByVendor(null),
        ));
    }

    /**
     * vendor id => [user id => ISO date], for features that record who did
     * it. Empty for business-wide features.
     *
     * @return array<int, array<int, string>>
     */
    public function usersByVendor(Feature $feature): array
    {
        if (! $feature->supportsUserLevel()) {
            return [];
        }

        return Cache::remember(self::CACHE_PREFIX.'users:'.$feature->key(), self::CACHE_SECONDS, function () use ($feature): array {
            $vendorIds = array_keys($this->firstUseByVendor($feature));

            if ($vendorIds === []) {
                return [];
            }

            return array_map(
                fn (array $users) => array_map(fn ($at) => $at->toIso8601String(), $users),
                $feature->firstUseByUser($vendorIds),
            );
        });
    }

    /**
     * The businesses being reported on, as a set (vendor id => true).
     *
     * @return array<int, true>
     */
    public function population(array $filters = []): array
    {
        return $this->populationQuery($filters)
            ->pluck('vendors.id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Paid plan by default: a free shop can only reach Products and Shop
     * Profile, so counting it would make every other feature look unused.
     */
    private function populationQuery(array $filters): Builder
    {
        $plan = $filters['plan'] ?? self::PLAN_PAID;
        $from = filled($filters['joined_from'] ?? null) ? CarbonImmutable::parse($filters['joined_from'])->startOfDay() : null;
        $until = filled($filters['joined_until'] ?? null) ? CarbonImmutable::parse($filters['joined_until'])->endOfDay() : null;

        return DB::table('vendors')
            ->when($plan === self::PLAN_PAID, fn ($q) => $q->where(fn ($q) => $q->whereNull('vendors.plan')->orWhere('vendors.plan', '!=', Vendor::PLAN_FREE)))
            ->when($plan === self::PLAN_FREE, fn ($q) => $q->where('vendors.plan', Vendor::PLAN_FREE))
            ->when($from, fn ($q) => $q->where('vendors.created_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('vendors.created_at', '<=', $until));
    }
}
