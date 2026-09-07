<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves stock that was applied to the wrong branch onto the right one.
 *
 * Distinct from products:move-store, which re-homes the product itself. This
 * moves nothing but quantity: the products are already homed correctly, it is
 * the units that are filed against the wrong shelf. That happens because a
 * bulk stock adjustment writes every line to whichever store was active in
 * the panel when it was applied (StockAdjustment::apply reads
 * ActiveStore::currentId), so one wrong selection in the switcher sends a
 * whole vendor sheet to the wrong branch.
 *
 * Corrected by ledgered movement rather than by deleting the entries that did
 * it. Two reasons: the units are real — they are physically on the
 * destination's shelf, and deleting would drop them out of the vendor's total
 * as well as out of the branch — and this ledger is append-only everywhere
 * else in the app, so a correction that leaves no trace would be the one
 * inventory event nobody could later explain.
 *
 * Without --to, the stock is written off the source instead of moved. That is
 * for the other case: units that were never really anywhere, and should come
 * off the books rather than land somewhere new.
 */
class TransferStockBetweenStoresCommand extends Command
{
    protected $signature = 'stock:transfer
                            {vendor : Vendor id}
                            {from : Store id the stock is sitting in now}
                            {--to= : Store id to move it to. Left out, the stock is written off the source instead of moved.}
                            {--product= : Only this product id}
                            {--reason= : Recorded against every movement this makes}
                            {--force : Actually apply it. Without this, only reports what would change.}';

    protected $description = 'Move stock that was applied to the wrong store onto the right one';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendor');
        $fromId   = (int) $this->argument('from');
        $toId     = $this->option('to') !== null ? (int) $this->option('to') : null;
        $onlyId   = $this->option('product') !== null ? (int) $this->option('product') : null;

        if ($toId !== null && $toId === $fromId) {
            $this->error('Source and destination are the same store.');

            return self::FAILURE;
        }

        foreach (array_filter([$fromId, $toId]) as $storeId) {
            $store = Store::find($storeId);

            if (! $store || $store->vendor_id !== $vendorId) {
                $this->error("Store #{$storeId} does not belong to vendor #{$vendorId}.");

                return self::FAILURE;
            }
        }

        // Scoped through products so another vendor's rows at a shared store id
        // can never be swept up by a mistyped argument.
        $rows = ProductStoreStock::where('product_store_stock.store_id', $fromId)
            ->when($onlyId, fn ($q) => $q->where('product_store_stock.product_id', $onlyId))
            ->join('products', 'products.id', '=', 'product_store_stock.product_id')
            ->where('products.vendor_id', $vendorId)
            ->orderBy('product_store_stock.product_id')
            ->select('product_store_stock.*')
            ->get();

        $movable = $rows->filter(fn (ProductStoreStock $r) => $r->quantity > 0);

        if ($movable->isEmpty()) {
            $this->info('Nothing to move — no stock sitting in that store.');

            return self::SUCCESS;
        }

        $destination = $toId === null ? 'written off' : 'store #'.$toId;

        $this->newLine();
        $this->line($movable->count().' product(s) holding '.$movable->sum('quantity')." unit(s) in store #{$fromId}, to be {$destination}:");

        $products = Product::whereIn('id', $movable->pluck('product_id'))->get()->keyBy('id');

        $this->table(
            ['Product', 'Name', 'Units', 'Reserved', 'Home store'],
            $movable->take(30)->map(fn (ProductStoreStock $r) => [
                '#'.$r->product_id,
                $products[$r->product_id]->name ?? '(missing)',
                $r->quantity,
                $r->reserved,
                '#'.($products[$r->product_id]->store_id ?? '?'),
            ])->all(),
        );

        if ($movable->count() > 30) {
            $this->line('  … and '.($movable->count() - 30).' more.');
        }

        // Units a live order is holding at this specific branch. Moving them
        // would leave the reservation pointing at a shelf that no longer has
        // the goods, so they are left alone and named instead.
        $reserved = $movable->filter(fn (ProductStoreStock $r) => $r->reserved > 0);

        if ($reserved->isNotEmpty()) {
            $this->newLine();
            $this->warn($reserved->count().' product(s) have reserved units here and will be skipped:');

            foreach ($reserved as $r) {
                $this->line("  #{$r->product_id} {$products[$r->product_id]->name} — {$r->reserved} reserved");
            }
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run — nothing moved. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $reason = $this->option('reason')
            ?: ($toId === null
                ? "Stock removed from store #{$fromId} — applied to the wrong branch"
                : "Stock moved from store #{$fromId} to #{$toId} — applied to the wrong branch");

        $adjust  = app(AdjustStockAction::class);
        $moved   = 0;
        $units   = 0;
        $skipped = 0;
        $failed  = [];

        foreach ($movable as $row) {
            if ($row->reserved > 0) {
                $skipped++;
                continue;
            }

            $quantity = (int) $row->quantity;

            try {
                // Both halves together: a transfer that took the units off one
                // shelf but failed to land them on the other would destroy
                // stock, which is the one outcome worse than the mistake being
                // corrected.
                DB::transaction(function () use ($adjust, $row, $quantity, $fromId, $toId, $reason) {
                    $adjust->execute(
                        productId:       $row->product_id,
                        quantityChanged: -$quantity,
                        transactionType: 'store_transfer',
                        userId:          null,
                        reference:       'Store correction',
                        description:     $reason,
                        store:           $fromId,
                    );

                    if ($toId !== null) {
                        $adjust->execute(
                            productId:       $row->product_id,
                            quantityChanged: $quantity,
                            transactionType: 'store_transfer',
                            userId:          null,
                            reference:       'Store correction',
                            description:     $reason,
                            store:           $toId,
                        );
                    }
                });

                $moved++;
                $units += $quantity;
            } catch (Throwable $e) {
                $failed[] = "#{$row->product_id} ".($products[$row->product_id]->name ?? '').': '.$e->getMessage();
            }
        }

        $this->newLine();
        $this->info("{$moved} product(s), {$units} unit(s) ".($toId === null ? 'written off' : "moved to store #{$toId}").'.');

        if ($skipped > 0) {
            $this->warn("{$skipped} product(s) skipped — reserved units at the source.");
        }

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' product(s) could not be moved:');

            foreach ($failed as $f) {
                $this->line('  '.$f);
            }
        }

        return self::SUCCESS;
    }
}
