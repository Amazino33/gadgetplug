<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Exceptions\Assistant\AggregateNotSupported;
use App\Exceptions\Assistant\DateRangeTooLarge;
use App\Exceptions\Assistant\InvalidScope;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Services\Assistant\AssistantScope;
use App\Services\Assistant\CostFieldStripper;
use App\Support\Naira;
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
     * The vendor permission a user needs for this query — the same one that
     * gates the panel screen showing the same data. Null: anyone allowed to
     * use the assistant may ask it.
     */
    public function requiredPermission(): ?string
    {
        return null;
    }

    /**
     * Data whose panel screen is owner-only (supplier payables, affiliate
     * commissions): no role can be granted it.
     */
    public function ownerOnly(): bool
    {
        return false;
    }

    /**
     * Whether the answer IS cost, profit or margin (profit, stock at cost):
     * refused outright without view_cost_price, rather than returned with
     * the figure stripped and nothing left.
     */
    public function costSensitive(): bool
    {
        return false;
    }

    /**
     * A money figure as the model must quote it: naira, rounded to kobo, and
     * the display string worked out here. The model is told to repeat the
     * _display value and never to calculate money itself — so every total,
     * difference and percentage it states comes from code.
     *
     * @return array<string, float|string>
     */
    public static function naira(string $key, float|int|string|null $amount): array
    {
        $value = round((float) $amount, 2);

        return [
            $key => $value,
            "{$key}_display" => Naira::format($value, '₦0'),
        ];
    }

    /**
     * A percentage worked out in code, with its display string (e.g. "+12.5%").
     *
     * @return array<string, float|string>
     */
    protected static function percent(string $key, float $value): array
    {
        $value = round($value, 1);

        return [
            $key => $value,
            "{$key}_display" => ($value > 0 ? '+' : '').number_format($value, 1).'%',
        ];
    }

    /**
     * Template method performing strict security, scope, and date validations.
     *
     * Order, unchanged for shape and stores: the scope shape, then store
     * access (the isolation guard), then — new — whether this user's role
     * covers this data at all, then dates. Who is asking is the scope of the
     * current question, or the authenticated user with no vendor. Results lose
     * cost/profit/margin fields for anyone without view_cost_price.
     *
     * @param  array{store_id?: int, store_ids?: array<int>}  $scope
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function execute(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $access = $this->accessScope();

        $result = $this->scopedRun($access, $scope, $from, $to, $options);

        return $access?->canSeeCost() ? $result : CostFieldStripper::stripCost($result);
    }

    /**
     * Whether this user's role covers this query. Null access (nobody signed
     * in) passes only a query that needs nothing — the glossary.
     */
    public function mayBeAskedBy(?AssistantScope $access): bool
    {
        if (! $access) {
            return $this->requiredPermission() === null && ! $this->ownerOnly() && ! $this->costSensitive();
        }

        if ($this->ownerOnly() && ! $access->isOwner()) {
            return false;
        }

        if ($this->costSensitive() && ! $access->canSeeCost()) {
            return false;
        }

        $permission = $this->requiredPermission();

        return $permission === null || $access->can($permission);
    }

    /**
     * @param  array{store_id?: int, store_ids?: array<int>}  $scope
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function scopedRun(?AssistantScope $access, array $scope, CarbonInterface $from, CarbonInterface $to, array $options): array
    {
        if (! $this->requiresScopeAndDates()) {
            $hasSingle = array_key_exists('store_id', $scope) && $scope['store_id'] !== null;
            $hasMultiple = array_key_exists('store_ids', $scope) && $scope['store_ids'] !== null;

            if ($hasSingle || $hasMultiple) {
                $targetIds = $hasSingle
                    ? [(int) $scope['store_id']]
                    : array_values(array_map('intval', (array) $scope['store_ids']));

                $this->assertStoreAccess($access, $targetIds);
            }

            if (! $this->mayBeAskedBy($access)) {
                return QueryResult::empty('no_access');
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

        // 2. Store access (isolation guard before feature checks)
        $this->assertStoreAccess($access, $targetStoreIds);

        // 2b. Whether this user's role covers this data at all. Structured,
        // like every other "no" a query gives: the model is told there is no
        // access and nothing about the figure itself.
        if (! $this->mayBeAskedBy($access)) {
            return QueryResult::empty('no_access');
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
     * The scope of the question being answered (set by AskAssistantAction),
     * or — for a direct call — the authenticated user with no vendor, which
     * limits stores to the ones they own exactly as before. Null when nobody
     * is signed in: only a query needing neither stores nor a permission
     * (the glossary) can then run.
     */
    protected function accessScope(): ?AssistantScope
    {
        if ($scope = AssistantScope::current()) {
            return $scope;
        }

        $user = auth()->user();

        return $user ? AssistantScope::for($user) : null;
    }

    /**
     * @param  array<int>  $targetStoreIds
     *
     * @throws UnauthorizedStoreAccess
     */
    protected function assertStoreAccess(?AssistantScope $access, array $targetStoreIds): void
    {
        if (! $access) {
            throw new UnauthorizedStoreAccess('Authentication required for store queries.');
        }

        foreach ($targetStoreIds as $storeId) {
            if (! $access->allows($storeId)) {
                throw new UnauthorizedStoreAccess("Unauthorized access to store [{$storeId}].");
            }
        }
    }
}
