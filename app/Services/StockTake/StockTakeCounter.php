<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\User;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Counting on the phone: what a scan finds, saving one product's count in
 * one aisle the moment the counter taps Save, and handing the task in.
 *
 * Blind throughout: nothing here reads or returns stock. The figure the count
 * is compared with is worked out later, from the ledger, for counted_at.
 *
 * Every entry point checks that the task is this person's and still open.
 */
final class StockTakeCounter
{
    public const MODE_NEW     = 'new';
    public const MODE_ADD     = 'add';
    public const MODE_REPLACE = 'replace';

    /** A submit decision for a product nobody entered. */
    public const NOT_ON_SHELF = 'not_on_shelf';
    public const SKIP         = 'skip';

    public function __construct(private readonly StockTakeCatalogue $catalogue) {}

    /**
     * What a scanned code means in this task and aisle.
     *
     * @return array{status: string, product?: array, existing?: array|null, unlisted?: bool, counted_on?: string, counted_in?: array, code?: string}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function scan(User $user, StockTakeTask $task, Aisle $aisle, string $code): array
    {
        $this->mustWork($user, $task);
        $this->mustBeTaskAisle($task, $aisle);

        $found = $this->catalogue->lookup($task->vendor, $code);

        if (! $found) {
            return ['status' => 'unknown', 'code' => trim($code)];
        }

        [$product, $unitId] = $found;

        return $this->describe($task, $aisle, $product, $unitId);
    }

    /**
     * The same as a scan, for a product picked from a name search or from a
     * recount's list.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function pick(User $user, StockTakeTask $task, Aisle $aisle, Product $product): array
    {
        $this->mustWork($user, $task);
        $this->mustBeTaskAisle($task, $aisle);
        $this->sameShop($task, $product);

        return $this->describe($task, $aisle, $product, null);
    }

    /**
     * Save one product's count in one aisle.
     *
     * $lines are what was typed per unit: [['unit_id' => 3, 'qty' => 3], ['unit_id' => 1, 'qty' => 5]].
     * A second count of the same product in the same aisle must say whether
     * it adds to the first or replaces it; it is never decided silently.
     *
     * @param  array<int, array{unit_id: int|null, qty: mixed}>  $lines
     *
     * @throws AuthorizationException|ValidationException
     */
    public function save(User $user, StockTakeTask $task, Aisle $aisle, Product $product, array $lines, string $mode = self::MODE_NEW, ?string $saveId = null): StockTakeEntry
    {
        $this->mustWork($user, $task);

        // A Retry of a save that already landed (its answer was lost on the
        // way back): answer it again, change nothing. No Add-or-Replace
        // question, no second count.
        if ($saveId !== null && ($done = $this->replayed($task, $saveId))) {
            return $done;
        }

        $this->mustBeTaskAisle($task, $aisle);
        $this->sameShop($task, $product);
        $this->mustBeCountable($task, $product);

        [$base, $breakdown] = $this->measure($task, $product, $lines);

        try {
            $entry = DB::transaction(function () use ($user, $task, $aisle, $product, $base, $breakdown, $mode, $saveId) {
                $existing = StockTakeEntry::query()
                    ->where('stock_take_task_id', $task->id)
                    ->where('aisle_id', $aisle->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing && $mode === self::MODE_NEW) {
                    throw ValidationException::withMessages([
                        'mode' => 'Already counted ' . Quantity::format($existing->counted_qty_base) . ' here. Choose Add or Replace.',
                    ]);
                }

                if ($existing && $mode === self::MODE_ADD) {
                    $base      = Quantity::add($existing->counted_qty_base, $base);
                    $breakdown = self::mergeBreakdown($existing->entry_breakdown ?? [], $breakdown);
                }

                $values = [
                    'counted_qty_base' => $base,
                    'entry_breakdown'  => $breakdown,
                    'counted_by'       => $user->id,
                    // The server's clock, at the moment of saving: what the
                    // shelf held then is what this count is compared with.
                    'counted_at'       => now(),
                    'is_not_on_shelf'  => false,
                    'last_save_id'     => $saveId,
                ];

                if ($existing) {
                    $existing->update($values);

                    return $existing;
                }

                return StockTakeEntry::create($values + [
                    'vendor_id'          => $task->vendor_id,
                    'store_id'           => $task->store_id,
                    'stock_take_task_id' => $task->id,
                    'aisle_id'           => $aisle->id,
                    'product_id'         => $product->id,
                    'is_unlisted'        => ! $this->isMapped($task, $aisle, $product),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A retry racing its own first try: the first one won.
            if ($saveId !== null && ($done = $this->replayed($task, $saveId))) {
                return $done;
            }

            // Two different saves of the same product in the same aisle at once.
            throw ValidationException::withMessages(['mode' => 'This was just saved. Choose Add or Replace.']);
        }

        $this->mapAsCounted($user, $task, $aisle, $product);
        $this->started($task);

        return $entry;
    }

    /**
     * A count as the phone holds it, sent after the fact (offline counting).
     *
     * Absolute, not a change: the phone decided Add or Replace when the
     * counter pressed it, and sends what the entry now is. Sent twice, it
     * lands the same — which is what lets a batch be retried.
     *
     * $countedAt is the phone's time corrected to the server's clock; the
     * caller has already checked it is believable (see StockTakeSync).
     *
     * @param  array<int, array{unit_id: int|null, qty: mixed}>  $lines
     *
     * @throws AuthorizationException|ValidationException
     */
    public function setFromPhone(User $user, StockTakeTask $task, Aisle $aisle, Product $product, array $lines, CarbonInterface $countedAt, ?CarbonInterface $deviceAt, bool $flagged, float $damaged = 0, float $expired = 0): StockTakeEntry
    {
        $this->mustWork($user, $task);
        $this->mustBeTaskAisle($task, $aisle);
        $this->sameShop($task, $product);
        $this->mustBeCountable($task, $product);

        [$base, $breakdown] = $this->measure($task, $product, $lines);

        // Damaged and expired: in base units, apart from the good count (written off on approval).
        $damaged = round(max(0, $damaged), 3);
        $expired = round(max(0, $expired), 3);

        $entry = DB::transaction(function () use ($user, $task, $aisle, $product, $base, $breakdown, $countedAt, $deviceAt, $flagged, $damaged, $expired) {
            $existing = StockTakeEntry::query()
                ->where('stock_take_task_id', $task->id)
                ->where('aisle_id', $aisle->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $values = [
                'counted_qty_base'   => $base,
                'damaged_qty_base'   => $damaged,
                'expired_qty_base'   => $expired,
                'entry_breakdown'    => $breakdown,
                'counted_by'         => $user->id,
                'counted_at'         => $countedAt,
                'device_counted_at'  => $deviceAt,
                'counted_at_flagged' => $flagged,
                'is_not_on_shelf'    => false,
            ];

            if ($existing) {
                $existing->update($values);

                return $existing;
            }

            return StockTakeEntry::create($values + [
                'vendor_id'          => $task->vendor_id,
                'store_id'           => $task->store_id,
                'stock_take_task_id' => $task->id,
                'aisle_id'           => $aisle->id,
                'product_id'         => $product->id,
                'is_unlisted'        => ! $this->isMapped($task, $aisle, $product),
            ]);
        });

        $this->mapAsCounted($user, $task, $aisle, $product);
        $this->started($task);

        return $entry;
    }

    /**
     * The phone took a count back. By aisle and product, since the phone never
     * needs the server's entry id. Already gone is fine: it was a retry.
     *
     * @throws AuthorizationException
     */
    public function removeFromPhone(User $user, StockTakeTask $task, int $aisleId, int $productId): void
    {
        $this->mustWork($user, $task);

        StockTakeEntry::query()
            ->where('stock_take_task_id', $task->id)
            ->where('aisle_id', $aisleId)
            ->where('product_id', $productId)
            ->delete();
    }

    /** @throws AuthorizationException|ValidationException */
    public function remove(User $user, StockTakeTask $task, StockTakeEntry $entry): void
    {
        $this->mustWork($user, $task);

        if ((int) $entry->stock_take_task_id !== (int) $task->id) {
            throw ValidationException::withMessages(['entry' => 'That count is not on this task.']);
        }

        $entry->delete();
    }

    /**
     * A barcode the system does not know, found while counting: name, photo,
     * and how many were on the shelf, for a manager to resolve.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function recordUnknown(User $user, StockTakeTask $task, Aisle $aisle, string $name, ?string $barcode, ?UploadedFile $photo, mixed $qty, ?string $unit, ?string $saveId = null): StockTakeUnknownItem
    {
        $this->mustWork($user, $task);
        $this->mustBeTaskAisle($task, $aisle);

        if ($saveId !== null && ($done = StockTakeUnknownItem::query()->where('stock_take_task_id', $task->id)->where('save_id', $saveId)->first())) {
            return $done;
        }

        if ($qty !== null && $qty !== '' && (! is_numeric($qty) || (float) $qty < 0)) {
            throw ValidationException::withMessages(['qty' => 'Enter how many, or leave it empty.']);
        }

        $item = StockTakeUnknownItem::create([
            'vendor_id'          => $task->vendor_id,
            'store_id'           => $task->store_id,
            'stock_take_task_id' => $task->id,
            'aisle_id'           => $aisle->id,
            'barcode'            => $barcode !== null && trim($barcode) !== '' ? trim($barcode) : null,
            'name_entered'       => trim($name),
            'photo_path'         => $photo?->store("stock-take/unknown/{$task->vendor_id}", 'public'),
            'qty'                => $qty === null || $qty === '' ? null : Quantity::of($qty),
            'unit_entered'       => $unit !== null && trim($unit) !== '' ? trim($unit) : null,
            'created_by'         => $user->id,
            'save_id'            => $saveId,
        ]);

        $this->started($task);

        return $item;
    }

    /**
     * Products the task is expected to find that have no entry yet. For a
     * count: the products mapped to its aisles, less those done elsewhere in
     * the stock take. For a recount: the products it was sent to recount.
     *
     * @return Collection<int, Product>
     */
    public function missing(StockTakeTask $task): Collection
    {
        $expected = $task->isRecount()
            ? $task->products()->pluck('products.id')->map(fn ($id) => (int) $id)
            : StockTakeScope::expectedFor($task);

        $entered = $task->entries()->pluck('product_id')->map(fn ($id) => (int) $id);

        return Product::query()
            ->whereIn('id', $expected->diff($entered)->values()->all() ?: [0])
            ->orderBy('name')
            ->get();
    }

    /**
     * Hand the task in. Every product still missing must have been answered:
     * "not on shelf" saves a count of 0; "skip" leaves it uncounted for the
     * stock take. Nothing on the task can be changed afterwards.
     *
     * @param  array<int|string, string>  $decisions  product id => not_on_shelf | skip
     *
     * @throws AuthorizationException|ValidationException
     */
    public function submit(User $user, StockTakeTask $task, array $decisions): void
    {
        $this->mustWork($user, $task);

        if ($task->type === StockTakeTask::TYPE_MAPPING) {
            throw ValidationException::withMessages(['task' => 'A mapping task is finished, not submitted.']);
        }

        $missing    = $this->missing($task);
        $unanswered = $missing->filter(fn (Product $p) => ! in_array($decisions[$p->id] ?? null, [self::NOT_ON_SHELF, self::SKIP], true));

        if ($unanswered->isNotEmpty()) {
            throw ValidationException::withMessages([
                'decisions' => 'Say for each one whether it is not on the shelf or to be counted later: ' . $unanswered->pluck('name')->implode(', '),
            ]);
        }

        DB::transaction(function () use ($user, $task, $missing, $decisions) {
            $task = StockTakeTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (! $task->isOpen()) {
                throw ValidationException::withMessages(['task' => 'This count has already been submitted.']);
            }

            foreach ($missing as $product) {
                if ($decisions[$product->id] !== self::NOT_ON_SHELF) {
                    continue;
                }

                StockTakeEntry::create([
                    'vendor_id'          => $task->vendor_id,
                    'store_id'           => $task->store_id,
                    'stock_take_task_id' => $task->id,
                    'aisle_id'           => $this->aisleForAbsent($task, $product),
                    'product_id'         => $product->id,
                    'counted_by'         => $user->id,
                    'counted_qty_base'   => 0,
                    'entry_breakdown'    => [],
                    'counted_at'         => now(),
                    'is_not_on_shelf'    => true,
                ]);
            }

            $task->update([
                'status'       => StockTakeTask::STATUS_SUBMITTED,
                'started_at'   => $task->started_at ?? now(),
                'submitted_at' => now(),
            ]);
        });

        // One result per product, for the approver.
        app(StockTakeResults::class)->build($task->fresh());
    }

    /** The entry a save with this id already wrote, if it did. */
    private function replayed(StockTakeTask $task, string $saveId): ?StockTakeEntry
    {
        return StockTakeEntry::query()
            ->where('stock_take_task_id', $task->id)
            ->where('last_save_id', $saveId)
            ->first();
    }

    /** The count, in base units, and the breakdown as it is stored. */
    private function measure(StockTakeTask $task, Product $product, array $lines): array
    {
        $units      = ProductUnit::query()->where('product_id', $product->id)->get()->keyBy('id');
        $halves     = Quantity::allowsHalves($task->vendor);
        $base       = 0;
        $breakdown  = [];
        $anyEntered = false;

        foreach ($lines as $line) {
            $raw = $line['qty'] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $qty = Quantity::parse($raw);

            if ($qty === null || $qty < 0) {
                throw ValidationException::withMessages(['lines' => 'Quantities must be numbers, 0 or more.']);
            }

            if (! ($halves ? Quantity::isHalfStep($qty) : Quantity::isWhole($qty))) {
                throw ValidationException::withMessages([
                    'lines' => $halves ? 'Count in whole units or halves.' : 'Count in whole units.',
                ]);
            }

            $unitId = $line['unit_id'] ?? null;
            $unit   = $unitId !== null ? $units->get((int) $unitId) : $units->firstWhere('is_base', true);

            if ($unitId !== null && ! $unit) {
                throw ValidationException::withMessages(['lines' => 'That unit does not belong to this product.']);
            }

            $factor = max(1, (int) ($unit?->conversion_factor ?? 1));
            $anyEntered = true;

            if (Quantity::of($qty) == 0) {
                continue;
            }

            $base = Quantity::add($base, Quantity::mul($qty, $factor));
            $breakdown[] = [
                'unit_id' => $unit?->id,
                'unit'    => $unit?->name ?? ($product->base_unit ?: 'Piece'),
                'factor'  => $factor,
                'qty'     => Quantity::of($qty),
            ];
        }

        if (! $anyEntered) {
            throw ValidationException::withMessages(['lines' => 'Enter how many you counted (0 if none).']);
        }

        return [$base, $breakdown];
    }

    /** Add a second count's units to the first's, unit by unit. */
    private static function mergeBreakdown(array $first, array $second): array
    {
        $merged = collect($first)->keyBy(fn ($l) => (string) ($l['unit_id'] ?? 'base'));

        foreach ($second as $line) {
            $key = (string) ($line['unit_id'] ?? 'base');
            $merged[$key] = isset($merged[$key])
                ? array_merge($merged[$key], ['qty' => Quantity::add($merged[$key]['qty'], $line['qty'])])
                : $line;
        }

        return $merged->sortByDesc('factor')->values()->all();
    }

    private function describe(StockTakeTask $task, Aisle $aisle, Product $product, ?int $unitId): array
    {
        if ($task->isRecount() && ! $task->products()->whereKey($product->id)->exists()) {
            return [
                'status'  => 'not_in_recount',
                'product' => $this->catalogue->format($product, $unitId),
            ];
        }

        if (! $task->isRecount() && $task->stockTake && ($done = StockTakeScope::countedWhere($task->stockTake, $product->id))) {
            return [
                'status'     => 'already_done',
                'product'    => $this->catalogue->format($product, $unitId),
                'counted_on' => $done['counted_at']->timezone('Africa/Lagos')->format('l j M'),
                'counted_in' => $done['aisles'],
            ];
        }

        $existing = StockTakeEntry::query()
            ->where('stock_take_task_id', $task->id)
            ->where('aisle_id', $aisle->id)
            ->where('product_id', $product->id)
            ->first();

        return [
            'status'   => 'ok',
            'product'  => $this->catalogue->format($product, $unitId),
            'unlisted' => ! $this->isMapped($task, $aisle, $product),
            'existing' => $existing ? self::entryJson($existing) : null,
        ];
    }

    /** An entry as the counter's screen sees it: what they typed. Nothing else. */
    public static function entryJson(StockTakeEntry $entry): array
    {
        return [
            'id'              => $entry->id,
            'aisle_id'        => $entry->aisle_id,
            'product_id'      => $entry->product_id,
            'counted'         => $entry->counted_qty_base,
            'breakdown'       => $entry->entry_breakdown ?? [],
            'is_unlisted'     => $entry->is_unlisted,
            'is_not_on_shelf' => $entry->is_not_on_shelf,
            'counted_at'      => $entry->counted_at?->toIso8601String(),
        ];
    }

    /**
     * Decision 5: done products are counted again only through a recount an
     * admin sends. Checked again on save, not only on scan.
     */
    private function mustBeCountable(StockTakeTask $task, Product $product): void
    {
        if ($task->isRecount()) {
            if (! $task->products()->whereKey($product->id)->exists()) {
                throw ValidationException::withMessages(['product_id' => "{$product->name} is not on this recount."]);
            }

            return;
        }

        if ($task->stockTake && ($done = StockTakeScope::countedWhere($task->stockTake, $product->id))) {
            throw ValidationException::withMessages([
                'product_id' => "Already counted on {$done['counted_at']->timezone('Africa/Lagos')->format('l j M')} in " . implode(', ', $done['aisles']) . ' — skip.',
            ]);
        }
    }

    private function isMapped(StockTakeTask $task, Aisle $aisle, Product $product): bool
    {
        // A quick area has no list to be off: everything found there belongs.
        // So does whatever a Quick count finds in a real aisle: it maps as it counts.
        if ($aisle->is_quick || $task->isQuick()) {
            return true;
        }

        return DB::table('aisle_products')->where('aisle_id', $aisle->id)->where('product_id', $product->id)->exists();
    }

    /** Where a product marked "not on shelf" was looked for. */
    private function aisleForAbsent(StockTakeTask $task, Product $product): int
    {
        if (! $task->isRecount()) {
            $mapped = DB::table('aisle_products')
                ->join('stock_take_task_aisles', 'stock_take_task_aisles.aisle_id', '=', 'aisle_products.aisle_id')
                ->where('stock_take_task_aisles.stock_take_task_id', $task->id)
                ->where('aisle_products.product_id', $product->id)
                ->value('aisle_products.aisle_id');

            if ($mapped) {
                return (int) $mapped;
            }
        }

        // A recount: where the original count found it, else where it is mapped.
        $original = StockTakeEntry::query()
            ->where('stock_take_task_id', $task->parent_task_id)
            ->where('product_id', $product->id)
            ->value('aisle_id');

        $aisle = $original ?? DB::table('aisle_products')
            ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
            ->where('aisles.store_id', $task->store_id)
            ->where('aisle_products.product_id', $product->id)
            ->value('aisles.id');

        if (! $aisle) {
            throw ValidationException::withMessages(['decisions' => "{$product->name} has no aisle to record it against. Count it, or skip it."]);
        }

        return (int) $aisle;
    }

    /** @throws AuthorizationException */
    private function mustWork(User $user, StockTakeTask $task): void
    {
        if ($task->type === StockTakeTask::TYPE_MAPPING || ! StockTakeAccess::canWorkOn($user, $task)) {
            throw new AuthorizationException('This task is not open for you.');
        }
    }

    /**
     * A count is made in one of the task's aisles. A recount may find the
     * product in any active aisle of the branch.
     */
    private function mustBeTaskAisle(StockTakeTask $task, Aisle $aisle): void
    {
        $inTask = $task->aisles()->whereKey($aisle->id)->exists();
        // A recount looks wherever the product is; a Quick count walks the
        // whole branch (any real aisle, never another person's quick area).
        $roams  = $task->isRecount() || ($task->isQuick() && ! $aisle->is_quick);

        $ok = $aisle->is_active
            && (int) $aisle->store_id === (int) $task->store_id
            && ($inTask || $roams);

        if (! $ok) {
            throw ValidationException::withMessages(['aisle_id' => 'That aisle is not part of this task.']);
        }

        if (! $inTask && $task->isQuick() && ($holder = $task->aisleHeldByOther($aisle->id))) {
            throw ValidationException::withMessages(['aisle_id' => "{$aisle->label()} is with {$holder}'s count. Pick another aisle."]);
        }
    }

    /**
     * A Quick count maps as it counts: a product counted in a real aisle is on
     * that aisle's map from then on (source "count"), with no mapping walk first.
     */
    private function mapAsCounted(User $user, StockTakeTask $task, Aisle $aisle, Product $product): void
    {
        if (! $task->isQuick() || $aisle->is_quick) {
            return;
        }

        try {
            DB::table('aisle_products')->insertOrIgnore([
                'vendor_id'  => $task->vendor_id,
                'aisle_id'   => $aisle->id,
                'product_id' => $product->id,
                'source'     => \App\Models\AisleProduct::SOURCE_COUNT,
                'mapped_by'  => $user->id,
                'mapped_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let the map stop a count from saving.
        }
    }

    private function sameShop(StockTakeTask $task, Product $product): void
    {
        if ((int) $product->vendor_id !== (int) $task->vendor_id) {
            throw ValidationException::withMessages(['product_id' => 'That product is not one of this shop\'s.']);
        }
    }

    private function started(StockTakeTask $task): void
    {
        if ($task->status === StockTakeTask::STATUS_ASSIGNED) {
            $task->update(['status' => StockTakeTask::STATUS_IN_PROGRESS, 'started_at' => now()]);
        }
    }
}
