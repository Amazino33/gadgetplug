<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\DestinationResolver;
use App\Support\Engagement\Moments\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * One feature of the app, as the tips and Learn system sees it.
 *
 * Code decides what a feature is, where it lives, who can reach it and how its
 * use is detected. The words users read are in the app_features row of the
 * same key, which admins edit. To add a feature: write a subclass, list it in
 * FeatureRegistry, run `php artisan features:sync`. See docs/engagement/README.md.
 */
abstract class Feature
{
    /** Stable key. Stored on tips, events and app_features: never rename it. */
    abstract public function key(): string;

    /** Starting copy for the app_features row. Admins can change it afterwards. */
    abstract public function defaultTitle(): string;

    abstract public function defaultDescription(): string;

    /** @return array<int, UsageSource> */
    abstract protected function usageSources(): array;

    /** Panels the feature lives in. */
    public function panels(): array
    {
        return ['vendor'];
    }

    /** Named route of the page "Show me" opens, or null if there is no page. */
    public function destinationRoute(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function destinationParams(): array
    {
        return [];
    }

    /** Where "Show me" goes for this vendor; null when there is nowhere to send them. */
    public function destinationUrl(Vendor $vendor): ?string
    {
        $route = $this->destinationRoute();

        return $route ? app(DestinationResolver::class)->url($route, $this->destinationParams(), $vendor) : null;
    }

    /**
     * Whether the signed-in user can use this feature at this vendor.
     *
     * Defaults to the destination page's own canAccess(), so a tip or a Learn
     * card can never be more generous than the page itself. Features without a
     * Filament page override this.
     */
    public function canAccess(User $user, Vendor $vendor): bool
    {
        $route = $this->destinationRoute();

        return $route !== null && app(DestinationResolver::class)->canAccess($route, $user, $vendor);
    }

    /** @return array<int, class-string<Moment>> */
    public function moments(): array
    {
        return [];
    }

    /** True when at least one source records which person used it. */
    public function supportsUserLevel(): bool
    {
        foreach ($this->usageSources() as $source) {
            if ($source->recordsUser()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether "used it" for this person means the whole business.
     *
     * Owners (and platform admins looking in) are judged on the business: an
     * owner whose storekeeper records debts every day is using debts. Staff are
     * judged on their own use where the data says who did it. Everything that
     * asks "has this person used it" goes through this one rule.
     */
    public function isBusinessWideFor(bool $isOwnerOrAdmin): bool
    {
        return $isOwnerOrAdmin || ! $this->supportsUserLevel();
    }

    /** @return array<int, UsageSource> */
    public function sources(): array
    {
        return $this->usageSources();
    }

    /**
     * When this vendor, or this person at this vendor, first used the feature.
     * Pass no user for business-wide use.
     */
    public function firstUsedAt(int $vendorId, ?int $userId = null): ?CarbonImmutable
    {
        $earliest = null;

        foreach ($this->usageSources() as $source) {
            if ($userId === null) {
                $earliest = $this->earlier($earliest, $source->newQuery()
                    ->where($source->vendorColumn, $vendorId)
                    ->min($source->usedAtColumn));

                continue;
            }

            foreach ($source->userColumns as $userColumn => $usedAtColumn) {
                $earliest = $this->earlier($earliest, $source->newQuery()
                    ->where($source->vendorColumn, $vendorId)
                    ->where($userColumn, $userId)
                    ->min($usedAtColumn));
            }
        }

        return $earliest;
    }

    /**
     * First use per vendor, for reports.
     *
     * @param  array<int, int>|null  $vendorIds  null for every vendor
     * @return array<int, CarbonImmutable> vendor id => first use
     */
    public function firstUseByVendor(?array $vendorIds = null): array
    {
        $result = [];

        foreach ($this->usageSources() as $source) {
            $rows = $this->grouped($source->newQuery(), [$source->vendorColumn], $source->usedAtColumn, $source->vendorColumn, $vendorIds);

            foreach ($rows as $row) {
                $vendorId = (int) $row->g0;
                $result[$vendorId] = $this->earlier($result[$vendorId] ?? null, $row->first_used);
            }
        }

        return $result;
    }

    /**
     * First use per person, for reports. Empty for business-wide features.
     *
     * @param  array<int, int>  $vendorIds
     * @return array<int, array<int, CarbonImmutable>> vendor id => [user id => first use]
     */
    public function firstUseByUser(array $vendorIds): array
    {
        $result = [];

        foreach ($this->usageSources() as $source) {
            foreach ($source->userColumns as $userColumn => $usedAtColumn) {
                $query = $source->newQuery()->whereNotNull($userColumn);
                $rows = $this->grouped($query, [$source->vendorColumn, $userColumn], $usedAtColumn, $source->vendorColumn, $vendorIds);

                foreach ($rows as $row) {
                    $vendorId = (int) $row->g0;
                    $userId = (int) $row->g1;
                    $result[$vendorId][$userId] = $this->earlier($result[$vendorId][$userId] ?? null, $row->first_used);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $groupColumns
     * @param  array<int, int>|null  $vendorIds
     */
    private function grouped(Builder $query, array $groupColumns, string $usedAtColumn, string $vendorColumn, ?array $vendorIds): iterable
    {
        $grammar = $query->getGrammar();

        if ($vendorIds !== null) {
            if ($vendorIds === []) {
                return [];
            }

            $query->whereIn($vendorColumn, $vendorIds);
        }

        $query->columns = null;

        foreach ($groupColumns as $i => $column) {
            $query->selectRaw($grammar->wrap($column).' as g'.$i);
        }

        return $query
            ->selectRaw('MIN('.$grammar->wrap($usedAtColumn).') as first_used')
            ->groupBy($groupColumns)
            ->get();
    }

    private function earlier(?CarbonImmutable $current, mixed $candidate): ?CarbonImmutable
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }

        $candidate = $candidate instanceof \DateTimeInterface
            ? CarbonImmutable::instance($candidate)
            : CarbonImmutable::parse((string) $candidate, config('app.timezone', 'UTC'));

        return $current === null || $candidate->lt($current) ? $candidate : $current;
    }
}
