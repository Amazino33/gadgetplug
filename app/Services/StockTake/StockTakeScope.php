<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which products a stock take is about, and where each one stands.
 *
 *   in scope    every product mapped to an active aisle of the branch — or,
 *               for a stock take carried over from another, the products it
 *               carried. Once the stock take has a Quick count (no aisles),
 *               also every product the branch holds stock of: nothing was
 *               mapped, so "uncounted" has to mean "not counted by anyone"
 *   done        has an entry in a submitted task of this stock take (decision
 *               5). Done is done: new tasks leave it out, a later scan is
 *               refused, and only a recount sent by an admin counts it again
 *   uncounted   in scope and not done
 *   not mapped  sellable, has stock at the branch, but sits on no active
 *               aisle — so no count of aisles will ever reach it
 *
 * Plain id sets and queries; no stock figures leave this class.
 */
final class StockTakeScope
{
    /** Task statuses whose entries make a product done. */
    public const HANDED_IN = [
        StockTakeTask::STATUS_SUBMITTED,
        StockTakeTask::STATUS_APPROVED,
        StockTakeTask::STATUS_PARTIALLY_APPROVED,
    ];

    /** @return Collection<int, int> */
    public static function inScope(StockTake $stockTake): Collection
    {
        if ($stockTake->carried_product_ids !== null) {
            return collect($stockTake->carried_product_ids)->map(fn ($id) => (int) $id)->unique()->values();
        }

        $mapped = self::mappedAt((int) $stockTake->store_id);

        if (! self::hasQuickCount($stockTake)) {
            return $mapped;
        }

        return $mapped
            ->merge(self::stockedQuery((int) $stockTake->vendor_id, (int) $stockTake->store_id)->pluck('products.id')->map(fn ($id) => (int) $id))
            ->unique()
            ->values();
    }

    /** Has a Quick count task (one with a private area instead of aisles), whatever its status. */
    public static function hasQuickCount(StockTake $stockTake): bool
    {
        return DB::table('stock_take_task_aisles')
            ->join('stock_take_tasks', 'stock_take_tasks.id', '=', 'stock_take_task_aisles.stock_take_task_id')
            ->join('aisles', 'aisles.id', '=', 'stock_take_task_aisles.aisle_id')
            ->where('stock_take_tasks.stock_take_id', $stockTake->id)
            ->where('aisles.is_quick', true)
            ->exists();
    }

    /**
     * Products mapped to any active aisle of a branch.
     *
     * @return Collection<int, int>
     */
    public static function mappedAt(int $storeId): Collection
    {
        return DB::table('aisle_products')
            ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
            ->where('aisles.store_id', $storeId)
            ->where('aisles.is_active', true)
            ->distinct()
            ->pluck('aisle_products.product_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * Products with an entry in a handed-in task of this stock take.
     *
     * @return Collection<int, int>
     */
    public static function done(StockTake $stockTake): Collection
    {
        return StockTakeEntry::query()
            ->whereIn('stock_take_task_id', self::handedInTasks($stockTake))
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** @return Collection<int, int> */
    public static function uncounted(StockTake $stockTake): Collection
    {
        return self::inScope($stockTake)->diff(self::done($stockTake))->values();
    }

    /**
     * Products with an approved result in this stock take.
     *
     * @return Collection<int, int>
     */
    public static function approved(StockTake $stockTake): Collection
    {
        return StockTakeResult::query()
            ->where('stock_take_id', $stockTake->id)
            ->where('status', StockTakeResult::STATUS_APPROVED)
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * The dashboard's numbers.
     *
     * @return array{in_scope: int, counted: int, approved: int, remaining: int, counted_off_map: int, percent: int}
     */
    public static function progress(StockTake $stockTake): array
    {
        $scope    = self::inScope($stockTake);
        $done     = self::done($stockTake);
        $counted  = $scope->intersect($done)->count();
        $approved = $scope->intersect(self::approved($stockTake))->count();

        return [
            'in_scope'        => $scope->count(),
            'counted'         => $counted,
            'approved'        => $approved,
            'remaining'       => $scope->count() - $counted,
            // Found and counted somewhere, but on no aisle's map.
            'counted_off_map' => $done->diff($scope)->count(),
            'percent'         => $scope->isEmpty() ? 0 : (int) floor($counted * 100 / $scope->count()),
        ];
    }

    /**
     * What a count task is expected to find: the products mapped to its
     * aisles, less those already done in this stock take. The missing-items
     * check at submit asks about exactly these.
     *
     * @return Collection<int, int>
     */
    public static function expectedFor(StockTakeTask $task): Collection
    {
        $mapped = DB::table('aisle_products')
            ->whereIn('aisle_id', $task->aisles()->pluck('aisles.id'))
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id);

        if (! $task->stockTake) {
            return $mapped->values();
        }

        return $mapped->diff(self::done($task->stockTake))->values();
    }

    /**
     * When and where a done product was counted, for "Already counted on
     * Tuesday in Aisle 2 — skip".
     *
     * @return array{counted_at: \Carbon\CarbonInterface, aisles: array<int, string>}|null
     */
    public static function countedWhere(StockTake $stockTake, int $productId): ?array
    {
        $entries = StockTakeEntry::query()
            ->whereIn('stock_take_task_id', self::handedInTasks($stockTake))
            ->where('product_id', $productId)
            ->with('aisle')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        return [
            'counted_at' => $entries->max('counted_at'),
            'aisles'     => $entries->map(fn (StockTakeEntry $e) => $e->aisle?->label())->filter()->unique()->values()->all(),
        ];
    }

    /** Sellable products the branch holds stock of (what a whole-shop count is about). */
    public static function stockedQuery(int $vendorId, int $storeId): Builder
    {
        return Product::query()
            ->where('products.vendor_id', $vendorId)
            ->whereNull('products.source_product_id')
            ->where(fn (Builder $q) => $q->where('products.is_service', false)->orWhereNull('products.is_service'))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('product_store_stock')
                ->whereColumn('product_store_stock.product_id', 'products.id')
                ->where('product_store_stock.store_id', $storeId)
                ->where('product_store_stock.quantity', '!=', 0));
    }

    /**
     * Sellable products with stock at the branch that no active aisle lists.
     */
    public static function notMappedQuery(int $vendorId, int $storeId): Builder
    {
        return self::stockedQuery($vendorId, $storeId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('aisle_products')
                ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
                ->whereColumn('aisle_products.product_id', 'products.id')
                ->where('aisles.store_id', $storeId)
                ->where('aisles.is_active', true));
    }

    /**
     * Products on the chosen aisles that also sit on another active aisle
     * not chosen. All of a product's places should be counted the same day,
     * or stock moved between them in the meantime is counted twice or not at
     * all. Products already done are left out — they are not being counted.
     *
     * @param  array<int, int>  $aisleIds
     * @return array<int, array{product_id: int, product: string, elsewhere: array<int, array{id: int, label: string, held_by: string|null}>}>
     */
    public static function sameDayWarnings(StockTake $stockTake, array $aisleIds): array
    {
        $chosen = collect($aisleIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($chosen->isEmpty()) {
            return [];
        }

        $done = self::done($stockTake)->all();

        $products = DB::table('aisle_products')
            ->whereIn('aisle_id', $chosen)
            ->whereNotIn('product_id', $done ?: [0])
            ->distinct()
            ->pluck('product_id');

        $others = DB::table('aisle_products')
            ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
            ->where('aisles.store_id', $stockTake->store_id)
            ->where('aisles.is_active', true)
            ->whereIn('aisle_products.product_id', $products)
            ->whereNotIn('aisle_products.aisle_id', $chosen)
            ->get(['aisle_products.product_id', 'aisles.id as aisle_id']);

        if ($others->isEmpty()) {
            return [];
        }

        $aisles = Aisle::query()->whereIn('id', $others->pluck('aisle_id')->unique())->get()->keyBy('id');
        $names  = Product::query()->whereIn('id', $others->pluck('product_id')->unique())->pluck('name', 'id');
        $holders = self::aisleHolders($stockTake);

        return $others->groupBy('product_id')
            ->map(fn (Collection $rows, $productId) => [
                'product_id' => (int) $productId,
                'product'    => (string) ($names[$productId] ?? "#{$productId}"),
                'elsewhere'  => $rows->map(fn ($row) => [
                    'id'      => (int) $row->aisle_id,
                    'label'   => $aisles[$row->aisle_id]?->label() ?? "#{$row->aisle_id}",
                    'held_by' => $holders[(int) $row->aisle_id] ?? null,
                ])->sortBy('label')->values()->all(),
            ])
            ->sortBy('product')
            ->values()
            ->all();
    }

    /**
     * Aisles held by an open count task of this stock take, and by whom.
     *
     * @return array<int, string>  aisle id => assignee name
     */
    public static function aisleHolders(StockTake $stockTake): array
    {
        return DB::table('stock_take_task_aisles')
            ->join('stock_take_tasks', 'stock_take_tasks.id', '=', 'stock_take_task_aisles.stock_take_task_id')
            ->join('users', 'users.id', '=', 'stock_take_tasks.assigned_to')
            ->where('stock_take_tasks.stock_take_id', $stockTake->id)
            ->whereNotNull('stock_take_task_aisles.lock_key')
            ->pluck('users.name', 'stock_take_task_aisles.aisle_id')
            ->mapWithKeys(fn ($name, $aisleId) => [(int) $aisleId => (string) $name])
            ->all();
    }

    private static function handedInTasks(StockTake $stockTake): Builder
    {
        return StockTakeTask::query()
            ->select('id')
            ->where('stock_take_id', $stockTake->id)
            ->whereIn('type', [StockTakeTask::TYPE_COUNT, StockTakeTask::TYPE_RECOUNT])
            ->whereIn('status', self::HANDED_IN);
    }
}
