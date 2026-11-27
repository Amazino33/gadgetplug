<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\Vendor;
use App\Services\Purchases\PurchaseCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything a phone needs to count a task with no network, in one download.
 *
 *   task        id, type, status, due date, the aisles with what each is
 *               expected to hold in mapping order (usually shelf order)
 *   products    every product of the shop a counter could find on a shelf —
 *               not only the task's aisles, since a product turns up where it
 *               should not — with every barcode mapped to its unit, the units,
 *               and a small picture
 *   done        products already counted in a handed-in task of this stock
 *               take, with when and where (decision 5)
 *   entries     what has already been counted on this task, so a task begun
 *               before the phone counted offline carries on from there
 *   server_time for the phone's clock correction
 *
 * Blind like every counting screen: no stock, cost or price anywhere in it.
 *
 * Compact on purpose — short keys, arrays not objects — because a shop of
 * 2,000 products downloads it on shop-floor data.
 */
final class StockTakePack
{
    public function __construct(private readonly PurchaseCatalogue $catalogue) {}

    public function build(StockTakeTask $task): array
    {
        $task->loadMissing(['stockTake', 'vendor']);

        return [
            'v'           => 1,
            'server_time' => now()->toIso8601ZuluString('millisecond'),
            'task'        => $this->task($task),
            'products'    => $this->cachedProducts($task->vendor, (int) $task->store_id),
            'done'        => $this->done($task),
            'entries'     => $this->entries($task),
        ];
    }

    /**
     * Every product of the shop a counter could find on a shelf, as compact
     * rows (see product()). The task pack carries these, and so does the
     * mapping download, so a scan while mapping is found on the phone.
     */
    public function products(Vendor $vendor, ?int $storeId = null): array
    {
        $stock = $storeId
            ? DB::table('product_store_stock')->where('store_id', $storeId)->pluck('quantity', 'product_id')
            : null;

        return $this->catalogue->receivable($vendor)
            ->with(['media'])
            ->orderBy('id')
            ->get(['products.id', 'products.name', 'products.sku', 'products.barcode', 'products.base_unit'])
            ->map(function (Product $p) use ($stock) {
                $row = $this->product($p);
                if ($stock !== null) {
                    $row[] = self::ceiling((float) ($stock[$p->id] ?? 0), $row[5]);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * The products part of a pack, which is most of its cost (2,000 products
     * with their units, barcodes and pictures take a second or two to read).
     * Kept until a product, unit, barcode or picture changes — and for an hour
     * at most, since the "is that right?" ceilings follow stock loosely — so
     * the second counter, or the same phone downloading again, gets it at once.
     */
    public function cachedProducts(Vendor $vendor, int $storeId): array
    {
        $key = "stock-take.pack-products.{$vendor->id}.{$storeId}." . $this->productsVersion($vendor) . '.' . now()->format('YmdH');

        return Cache::remember($key, now()->addMinutes(70), fn () => $this->products($vendor, $storeId));
    }

    /**
     * Above this, the phone asks "Save 4,800 pieces?" before saving — the
     * extra-zero typo. Blind: never the stock itself, only about three times
     * it (or two of the biggest pack), rounded up to 1, 2 or 5 × 10ⁿ, and it
     * is never shown; a count above it is simply confirmed.
     *
     * @param  array<int, array{0:int,1:string,2:int}>  $units  [id, name, factor], biggest first
     */
    public static function ceiling(float $stock, array $units): int
    {
        $pack  = (int) ($units[0][2] ?? 1);
        $limit = max(20, 3 * max(0, $stock), 2 * $pack);
        $power = 10 ** (int) floor(log10($limit));

        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $power >= $limit) {
                return (int) ($step * $power);
            }
        }

        return (int) (10 * $power);
    }

    /**
     * Changes whenever products() would: a product, a unit, a barcode or a
     * picture added, changed or removed. A few cheap aggregates, so a phone
     * can ask "anything new?" without downloading the list.
     */
    public function productsVersion(Vendor $vendor): string
    {
        $products = DB::table('products')->where('vendor_id', $vendor->id)
            ->selectRaw('COUNT(*) as n, MAX(updated_at) as at')->first();
        $barcodes = DB::table('product_barcodes')->where('vendor_id', $vendor->id)
            ->selectRaw('COUNT(*) as n, MAX(updated_at) as at')->first();
        $units = DB::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->where('products.vendor_id', $vendor->id)
            ->selectRaw('COUNT(*) as n, MAX(product_units.updated_at) as at')->first();
        $media = DB::table('media')
            ->join('products', 'products.id', '=', 'media.model_id')
            ->where('media.model_type', (new Product())->getMorphClass())
            ->where('products.vendor_id', $vendor->id)
            ->selectRaw('COUNT(*) as n, MAX(media.updated_at) as at')->first();

        return substr(sha1(json_encode([$products, $barcodes, $units, $media])), 0, 16);
    }

    private function task(StockTakeTask $task): array
    {
        $quick    = $task->isQuick();
        $aisleIds = $task->isRecount()
            ? Aisle::query()->where('store_id', $task->store_id)->active()->listed()->ordered()->pluck('id')
            : $task->aisles()->ordered()->pluck('aisles.id');

        // A Quick count walks the whole branch: its own area ("no aisle")
        // plus every real aisle, so the counter can say where they are.
        if ($quick) {
            $aisleIds = $aisleIds->merge(
                Aisle::query()->where('store_id', $task->store_id)->active()->listed()->ordered()->pluck('id')
            )->unique()->values();
        }

        $held = $quick && $task->stockTake ? StockTakeScope::aisleHolders($task->stockTake) : [];
        $own  = $quick ? $task->aisles()->pluck('aisles.id')->map(fn ($id) => (int) $id)->all() : [];

        $aisles = Aisle::query()->whereIn('id', $aisleIds)->ordered()->get();

        // What each aisle is expected to hold, in the order it was mapped:
        // the order someone walked the shelf, which is the order to count it.
        $mapped = DB::table('aisle_products')
            ->whereIn('aisle_id', $aisleIds)
            ->orderBy('mapped_at')
            ->orderBy('id')
            ->get(['aisle_id', 'product_id'])
            ->groupBy('aisle_id');

        return [
            'id'           => $task->id,
            'type'         => $task->type,
            'status'       => $task->status,
            'due_date'     => $task->due_date?->toDateString(),
            'updated_at'   => $task->updated_at?->toIso8601ZuluString(),
            'stock_take'   => $task->stockTake ? ['reference' => $task->stockTake->reference, 'name' => $task->stockTake->name] : null,
            'allow_halves' => (bool) $task->vendor->allow_half_quantities,
            // Count and map in one walk (Quick count): pick the aisle as you go.
            'quick'        => $quick,
            'aisles'       => $aisles->map(fn (Aisle $a) => [
                'id'       => $a->id,
                'label'    => $a->label(),
                // A Quick count's own area: no list to be off, and no aisle to pick.
                'quick'    => (bool) $a->is_quick,
                // Another person's aisle task holds it: not for this walk.
                'held_by'  => ! in_array($a->id, $own, true) ? ($held[$a->id] ?? null) : null,
                'expected' => $task->isRecount() || $quick
                    ? []
                    : ($mapped[$a->id] ?? collect())->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all(),
            // A recount counts these, wherever they turn up.
            'recount'      => $task->isRecount() ? $task->products()->pluck('products.id')->map(fn ($id) => (int) $id)->values()->all() : null,
        ];
    }

    /**
     * [id, name, sku, picture, base unit, units, barcodes] (+ ceiling, in a task pack)
     *   units     [[id, name, factor], …] biggest first
     *   barcodes  [[code, unit id or null], …] the main barcode included
     */
    private function product(Product $p): array
    {
        $units = $p->units
            ->where('is_active', true)
            ->sortByDesc('conversion_factor')
            ->map(fn (ProductUnit $u) => [(int) $u->id, $u->name, max(1, (int) $u->conversion_factor)])
            ->values()
            ->all();

        $codes = $p->barcodes
            ->map(fn ($b) => [(string) $b->barcode, $b->product_unit_id !== null ? (int) $b->product_unit_id : null])
            ->values();

        if ($p->barcode && ! $codes->contains(fn ($c) => $c[0] === $p->barcode)) {
            $codes->prepend([(string) $p->barcode, null]);
        }

        return [
            (int) $p->id,
            $p->name,
            $p->sku,
            $this->picture($p),
            $p->base_unit ?: 'Piece',
            $units,
            $codes->all(),
        ];
    }

    /** The smallest picture there is: the counting thumbnail, else the thumb. */
    private function picture(Product $p): ?string
    {
        $media = $p->getFirstMedia('product-images');

        if (! $media) {
            return null;
        }

        foreach (['count_thumb', 'thumb'] as $conversion) {
            if ($media->hasGeneratedConversion($conversion)) {
                return $media->getUrl($conversion);
            }
        }

        return null;
    }

    /** product id => [day counted, [aisles]] */
    private function done(StockTakeTask $task): array
    {
        if (! $task->stockTake || $task->isRecount()) {
            return [];
        }

        $entries = StockTakeEntry::query()
            ->whereIn('stock_take_task_id', StockTakeTask::query()
                ->select('id')
                ->where('stock_take_id', $task->stock_take_id)
                ->whereIn('type', [StockTakeTask::TYPE_COUNT, StockTakeTask::TYPE_RECOUNT])
                ->whereIn('status', StockTakeScope::HANDED_IN))
            ->with('aisle')
            ->get()
            ->groupBy('product_id');

        return $entries->mapWithKeys(fn ($rows, $productId) => [
            (int) $productId => [
                $rows->max('counted_at')->timezone('Africa/Lagos')->format('l j M'),
                $rows->map(fn (StockTakeEntry $e) => $e->aisle?->label())->filter()->unique()->values()->all(),
            ],
        ])->all();
    }

    /** What the task already holds: [aisle, product, counted, breakdown, counted at, unlisted, not on shelf, damaged, expired] */
    private function entries(StockTakeTask $task): array
    {
        return StockTakeEntry::query()
            ->where('stock_take_task_id', $task->id)
            ->orderBy('counted_at')
            ->get()
            ->map(fn (StockTakeEntry $e) => [
                (int) $e->aisle_id,
                (int) $e->product_id,
                $e->counted_qty_base,
                $e->entry_breakdown ?? [],
                $e->counted_at?->toIso8601ZuluString('millisecond'),
                (bool) $e->is_unlisted,
                (bool) $e->is_not_on_shelf,
                (float) $e->damaged_qty_base,
                (float) $e->expired_qty_base,
            ])
            ->values()
            ->all();
    }
}
