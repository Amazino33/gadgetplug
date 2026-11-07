<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Exceptions\Assistant\AggregateNotSupported;
use App\Exceptions\Assistant\DateRangeTooLarge;
use App\Exceptions\Assistant\InvalidScope;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\Store;
use Carbon\CarbonInterface;

abstract class BaseAssistantQuery
{
    /**
     * Whether this query supports aggregating metrics across multiple stores.
     */
    abstract public function supportsAggregate(): bool;

    /**
     * Executes the concrete domain query logic.
     *
     * @param  array{store_id?: int, store_ids?: array<int>}  $scope
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    abstract protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array;

    /**
     * Whether this query requires store scoping and date range validation.
     */
    protected function requiresScopeAndDates(): bool
    {
        return true;
    }

    /**
     * Template method performing strict security, scope, and date validations.
     *
     * @param  array{store_id?: int, store_ids?: array<int>}  $scope
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function execute(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        if (! $this->requiresScopeAndDates()) {
            $hasSingle = array_key_exists('store_id', $scope) && $scope['store_id'] !== null;
            $hasMultiple = array_key_exists('store_ids', $scope) && $scope['store_ids'] !== null;

            if ($hasSingle || $hasMultiple) {
                $targetIds = $hasSingle
                    ? [(int) $scope['store_id']]
                    : array_values(array_map('intval', (array) $scope['store_ids']));

                $this->assertStoreOwnership($targetIds);
            }

            return $this->run($scope, $from, $to, $options);
        }

        // 1. Validate Scope: Must have store_id XOR store_ids[]
        $hasSingle = array_key_exists('store_id', $scope) && $scope['store_id'] !== null;
        $hasMultiple = array_key_exists('store_ids', $scope) && $scope['store_ids'] !== null;

        if ($hasSingle === $hasMultiple) {
            throw new InvalidScope('Scope must provide either store_id or store_ids[], but not both and not neither.');
        }

        /** @var array<int> $targetStoreIds */
        if ($hasSingle) {
            $targetStoreIds = [(int) $scope['store_id']];
        } else {
            if (! is_array($scope['store_ids']) || count($scope['store_ids']) === 0) {
                throw new InvalidScope('store_ids must be a non-empty array of store IDs.');
            }
            $targetStoreIds = array_values(array_map('intval', $scope['store_ids']));
        }

        // 2. Store Ownership Check (isolation guard before feature checks)
        $user = auth()->user();
        if (! $user) {
            throw new UnauthorizedStoreAccess('Authentication required for store queries.');
        }

        if (! $user->isSuperAdmin()) {
            // Vendor-owner relationship: stores belonging to vendors owned by the authenticated user
            $allowedStoreIds = Store::query()
                ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
                ->pluck('id')
                ->all();

            foreach ($targetStoreIds as $storeId) {
                if (! in_array($storeId, $allowedStoreIds, true)) {
                    throw new UnauthorizedStoreAccess("Unauthorized access to store [{$storeId}].");
                }
            }
        }

        // 3. Validate Multi-store aggregate capability
        if (count($targetStoreIds) > 1 && ! $this->supportsAggregate()) {
            throw new AggregateNotSupported(static::class.' does not support multi-store aggregation.');
        }

        // 4. Date Validations
        if ($from->gt($to)) {
            throw new InvalidScope('From date must be earlier than or equal to to date.');
        }

        if ($to->gt(now()->addMinutes(1))) {
            throw new InvalidScope('To date cannot be in the future.');
        }

        if ($from->diffInDays($to) > 365) {
            throw new DateRangeTooLarge('Date range cannot exceed 365 days.');
        }

        // Normalize scope for child classes
        $normalizedScope = $scope;
        $normalizedScope['resolved_store_ids'] = $targetStoreIds;

        return $this->run($normalizedScope, $from, $to, $options);
    }

    /**
     * @param  array<int>  $targetStoreIds
     *
     * @throws UnauthorizedStoreAccess
     */
    protected function assertStoreOwnership(array $targetStoreIds): void
    {
        $user = auth()->user();
        if (! $user) {
            throw new UnauthorizedStoreAccess('Authentication required for store queries.');
        }

        if (! $user->isSuperAdmin()) {
            // Vendor-owner relationship: stores belonging to vendors owned by the authenticated user
            $allowedStoreIds = Store::query()
                ->whereHas('vendor', fn ($v) => $v->where('user_id', $user->id))
                ->pluck('id')
                ->all();

            foreach ($targetStoreIds as $storeId) {
                if (! in_array($storeId, $allowedStoreIds, true)) {
                    throw new UnauthorizedStoreAccess("Unauthorized access to store [{$storeId}].");
                }
            }
        }
    }
}
