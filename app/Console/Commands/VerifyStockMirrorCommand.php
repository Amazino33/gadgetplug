<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Read-only. Proves the claim the whole mirror design rests on: that
// products.stock_quantity / reserved_stock are exactly the sum of the
// product's per-store rows, for every product, with no exceptions.
//
// If this ever reports a mismatch, the per-store rows are the truth — they are
// what the five stock actions write — and the product columns are the stale
// copy. Nothing here repairs anything; a silent auto-fix would hide whichever
// bug caused the drift, which is the one thing worth knowing.
class VerifyStockMirrorCommand extends Command
{
    protected $signature = 'stock:verify-mirror {--vendor= : Limit to one vendor id}';

    protected $description = 'Check that every product stock mirror equals the sum of its per-store rows';

    public function handle(): int
    {
        $products = DB::table('products')
            ->leftJoin('product_store_stock', 'product_store_stock.product_id', '=', 'products.id')
            ->when($this->option('vendor'), fn ($q, $vendorId) => $q->where('products.vendor_id', $vendorId))
            ->groupBy('products.id', 'products.name', 'products.vendor_id', 'products.stock_quantity', 'products.reserved_stock')
            ->select('products.id', 'products.name', 'products.vendor_id')
            ->selectRaw('products.stock_quantity as mirror_quantity')
            ->selectRaw('products.reserved_stock as mirror_reserved')
            ->selectRaw('COALESCE(SUM(product_store_stock.quantity), 0) as store_quantity')
            ->selectRaw('COALESCE(SUM(product_store_stock.reserved), 0) as store_reserved')
            ->selectRaw('COUNT(product_store_stock.id) as store_rows')
            ->get();

        $drifted = $products->filter(fn ($row) => (int) $row->mirror_quantity !== (int) $row->store_quantity
            || (int) $row->mirror_reserved !== (int) $row->store_reserved);

        $storeless = $products->filter(fn ($row) => (int) $row->store_rows === 0);

        $this->line("Checked {$products->count()} product(s).");

        if ($storeless->isNotEmpty()) {
            // Not drift on its own — a product with no store row and a zero
            // mirror agrees perfectly. Worth surfacing because it means that
            // product has never held stock anywhere.
            $this->line("{$storeless->count()} have no store row at all.");
        }

        $misplaced = $this->stockAwayFromHomeStore();

        if ($drifted->isEmpty() && $misplaced->isEmpty()) {
            $this->info('Mirror is exact: every product matches the sum of its store rows.');
            $this->info('Every product stock sits at its home store.');

            return self::SUCCESS;
        }

        if ($misplaced->isNotEmpty()) {
            $this->error("{$misplaced->count()} product(s) hold stock away from their home store:");

            $this->table(
                ['Product', 'Home store', 'Stock sitting at'],
                $misplaced->map(fn ($row) => [
                    "#{$row->id} {$row->name}",
                    $row->home_store_id ?? 'none',
                    $row->stray_store_id,
                ])->all(),
            );

            $this->newLine();
            $this->line('A product belongs to one branch. Stock at any other store is unreachable from');
            $this->line('that branch inventory and till — re-home the product, or move the stock.');
            $this->newLine();
        }

        if ($drifted->isEmpty()) {
            return self::FAILURE;
        }

        $this->error("{$drifted->count()} product(s) drifted from their store rows:");

        $this->table(
            ['Product', 'Vendor', 'Mirror qty', 'Store qty', 'Mirror res', 'Store res'],
            $drifted->map(fn ($row) => [
                "#{$row->id} {$row->name}",
                $row->vendor_id,
                $row->mirror_quantity,
                $row->store_quantity,
                $row->mirror_reserved,
                $row->store_reserved,
            ])->all(),
        );

        $this->newLine();
        $this->line('The per-store rows are authoritative. Investigate what wrote the product columns directly.');

        return self::FAILURE;
    }

    /**
     * Products holding stock at a store that is not their home.
     *
     * Home store and the stock row are two records of "which branch", written
     * by different code paths — the product form and the stock actions. This
     * is what makes a divergence between them visible instead of silent: stock
     * parked outside the home store is unreachable from that branch's
     * inventory, count sheet and till, so it is invisible rather than merely
     * misfiled.
     */
    private function stockAwayFromHomeStore()
    {
        return DB::table('products as p')
            ->join('product_store_stock as pss', 'pss.product_id', '=', 'p.id')
            ->when($this->option('vendor'), fn ($q, $vendorId) => $q->where('p.vendor_id', $vendorId))
            // A null home is caught explicitly: SQL comparisons against NULL
            // are neither true nor false, so `pss.store_id != p.store_id`
            // alone would let a product with no home store through silently —
            // and that product is invisible in every branch's inventory, which
            // is exactly the condition worth shouting about.
            ->where(fn ($q) => $q
                ->whereNull('p.store_id')
                ->orWhereColumn('pss.store_id', '!=', 'p.store_id'))
            ->select('p.id', 'p.name')
            ->selectRaw('p.store_id as home_store_id')
            ->selectRaw('pss.store_id as stray_store_id')
            ->orderBy('p.id')
            ->get();
    }
}
