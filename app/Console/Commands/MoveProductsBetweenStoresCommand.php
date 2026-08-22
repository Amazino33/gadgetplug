<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

/**
 * Re-homes products that landed in the wrong store.
 *
 * A product's store is decided by whichever store was active in the panel at
 * the moment it was created — never by its brand, category, or name. Importing
 * a file of Oraimo products while "Itel Home" happened to be the active store
 * puts every one of them in Itel Home; nothing about the import reads brand to
 * route it anywhere else. This corrects that after the fact, the same way
 * moving one product by hand in the panel would: through the model, so
 * ProductObserver relocates its stock row and ledger along with it, not just
 * the label.
 *
 * A product with reserved stock at its current store cannot move — a live
 * order is holding that stock at that specific location — so those are
 * skipped and reported rather than silently left half-migrated.
 */
class MoveProductsBetweenStoresCommand extends Command
{
    protected $signature = 'products:move-store
                            {vendor : Vendor id}
                            {from : Store id to move products out of}
                            {to : Store id to move products into}
                            {--brand= : Only products with this exact brand}
                            {--force : Actually move them. Without this, only reports what would happen.}';

    protected $description = 'Move products from one of a vendor\'s stores to another';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendor');
        $fromId   = (int) $this->argument('from');
        $toId     = (int) $this->argument('to');
        $brand    = $this->option('brand');

        if ($fromId === $toId) {
            $this->error('Source and destination are the same store.');

            return self::FAILURE;
        }

        $products = Product::where('vendor_id', $vendorId)
            ->where('store_id', $fromId)
            ->when($brand, fn ($q) => $q->where('brand', $brand))
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->info('Nothing matches — no products to move.');

            return self::SUCCESS;
        }

        $this->line("{$products->count()} product(s) match".($brand ? " (brand: {$brand})" : '').'.');

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run — nothing moved. Re-run with --force to apply.');
            $this->table(['ID', 'Name', 'Brand'], $products->take(20)->map(fn (Product $p) => [$p->id, $p->name, $p->brand])->all());

            if ($products->count() > 20) {
                $this->line('  … and '.($products->count() - 20).' more.');
            }

            return self::SUCCESS;
        }

        $moved  = 0;
        $failed = [];

        foreach ($products as $product) {
            try {
                $product->update(['store_id' => $toId]);
                $moved++;
            } catch (LogicException $e) {
                // Reserved stock at the source store — a live order is holding
                // it there. Recorded, not fatal to the rest of the batch.
                $failed[] = ['id' => $product->id, 'name' => $product->name, 'reason' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed[] = ['id' => $product->id, 'name' => $product->name, 'reason' => $e->getMessage()];
            }
        }

        $this->info("{$moved} product(s) moved from store #{$fromId} to store #{$toId}.");

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' product(s) could not be moved:');

            foreach ($failed as $f) {
                $this->line("  #{$f['id']} {$f['name']}: {$f['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
