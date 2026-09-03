<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties one vendor's catalogue so they can re-add products from scratch.
 *
 * Products are NOT soft-deleted in this app, and several tables cascade off
 * products.product_id. Deleting a product that has ever been sold silently takes
 * its order lines and stock ledger with it, which is why this command reports
 * first and refuses history by default rather than leaving that to a UI click.
 */
class PurgeVendorProductsCommand extends Command
{
    protected $signature = 'products:purge
        {vendor? : Vendor id or slug. Optional when --store is given}
        {--store= : Limit to one branch, by store id or name. Only products homed there}
        {--force : Actually delete (default is a dry run)}
        {--with-history : Also delete products that have history (DESTROYS order lines, ledger, counts and POS/procurement lines)}';

    protected $description = "Delete a vendor's products, or one branch's, reporting exactly what goes with them";

    /** Cascades silently when a product is deleted — the dangerous ones. */
    private const CASCADES = [
        'order_items'                 => 'online order lines',
        'inventory_ledgers'           => 'stock ledger entries',
        'audit_sessions'              => 'audit sessions (count results)',
        'stock_accountability_entries'=> 'accountability entries',
    ];

    /**
     * Blocks the delete with a foreign-key error instead of cascading.
     *
     * blind_count_entries belongs here, not in CASCADES where it used to sit:
     * its foreign key is declared with a plain constrained() and so is
     * RESTRICT, not cascade. Listing it as a cascade told the operator those
     * rows would be swept away when in fact they stop the delete dead — which
     * is precisely the case anyone who has run an inventory count is in, and
     * --with-history crashed on it rather than reporting anything.
     */
    private const BLOCKERS = [
        'blind_count_entries' => 'inventory count entries',
        'pos_sale_items'      => 'POS sale lines',
        'procurement_items'   => 'procurement lines',
    ];

    /** Safe to clear first — no history value. */
    private const DETACH = ['wishlists', 'product_tag', 'product_store_stock', 'product_images'];

    public function handle(): int
    {
        $store = null;

        // A branch can name its own vendor, so the vendor argument becomes
        // optional once --store is given — an operator clearing one branch
        // should not have to know which vendor owns it.
        if ($this->option('store') !== null) {
            $store = $this->resolveStore(trim((string) $this->option('store')));

            if ($store === null) {
                return self::FAILURE;
            }
        }

        $needle = trim((string) ($this->argument('vendor') ?? ''));

        if ($needle === '' && $store === null) {
            $this->error('Name a vendor, or pass --store to clear a single branch.');

            return self::FAILURE;
        }

        if ($needle === '') {
            return $this->purge(Vendor::findOrFail($store->vendor_id), $store);
        }

        // id and slug are exact; name is a fuzzy convenience so "chip gadget"
        // works. A delete target must never be guessed, so an ambiguous name
        // lists the candidates and stops rather than picking one.
        $matches = Vendor::query()
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->orWhere('slug', $needle)
            ->orWhere('name', 'like', "%{$needle}%")
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No vendor matches \"{$needle}\".");
            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("\"{$needle}\" matches " . $matches->count() . ' vendors. Re-run with the exact id or slug:');
            foreach ($matches as $m) {
                $this->line("  id={$m->id}  slug={$m->slug}  {$m->name}");
            }
            return self::FAILURE;
        }

        $vendor = $matches->first();

        if ($store !== null && (int) $store->vendor_id !== (int) $vendor->id) {
            $this->error("Branch \"{$store->name}\" does not belong to {$vendor->name}.");

            return self::FAILURE;
        }

        return $this->purge($vendor, $store);
    }

    /**
     * Finds the branch to clear, refusing to guess between candidates for the
     * same reason the vendor lookup does: a delete target is never assumed.
     */
    private function resolveStore(string $needle): ?Store
    {
        $matches = Store::query()
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->orWhere('name', 'like', "%{$needle}%")
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No branch matches \"{$needle}\".");

            return null;
        }

        if ($matches->count() > 1) {
            $this->error("\"{$needle}\" matches " . $matches->count() . ' branches. Re-run with the exact id:');
            foreach ($matches as $m) {
                $this->line("  id={$m->id}  vendor_id={$m->vendor_id}  {$m->name}");
            }

            return null;
        }

        return $matches->first();
    }

    private function purge(Vendor $vendor, ?Store $store): int
    {
        // Homed at the branch, which is what the whole app means by a product
        // belonging to one: the till, the product list and the count sheet all
        // read products.store_id. A product homed elsewhere that merely holds a
        // few units here is another branch's product and is left alone.
        $ids = Product::where('vendor_id', $vendor->id)
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->pluck('id');

        $this->newLine();
        $this->line("Vendor:   <fg=cyan>{$vendor->name}</> (id {$vendor->id}, slug {$vendor->slug})");

        if ($store !== null) {
            $this->line("Branch:   <fg=cyan>{$store->name}</> (id {$store->id}) — only products homed here");
        }

        $this->line("Products: <fg=cyan>{$ids->count()}</>");

        if ($ids->isEmpty()) {
            $this->info('Catalogue is already empty. Nothing to do.');
            return self::SUCCESS;
        }

        // Which products carry history, and how much
        $blocked = collect(self::BLOCKERS)
            ->mapWithKeys(fn ($label, $table) => [$table => $this->idsWithRows($table, $ids)])
            ->filter(fn ($rows) => $rows->isNotEmpty());

        $cascading = collect(self::CASCADES)
            ->mapWithKeys(fn ($label, $table) => [$table => $this->countRows($table, $ids)])
            ->filter(fn ($n) => $n > 0);

        $withHistory = $blocked->flatten()->merge(
            $this->idsWithRows('order_items', $ids)
        )->unique();

        $this->newLine();
        if ($cascading->isEmpty() && $blocked->isEmpty()) {
            $this->info('No sales or stock history is attached. A clean delete.');
        } else {
            $this->warn('These products are not just catalogue rows:');
            foreach ($cascading as $table => $n) {
                $this->line("  <fg=yellow>{$n}</> " . self::CASCADES[$table] . " ({$table}) would be DELETED with them");
            }
            foreach ($blocked as $table => $rows) {
                $verdict = $this->option('with-history')
                    ? 'their rows will be DELETED first to clear the way'
                    : 'these BLOCK deletion';

                $this->line("  <fg=red>{$rows->count()}</> products have " . self::BLOCKERS[$table] . " ({$table}) — {$verdict}");
            }
        }

        // Said plainly because the tables above are not the whole cost. A sale
        // or purchase order keeps its own header row and its total while losing
        // the line that named this product, so those documents stop adding up
        // and any report reading lines rather than totals will change.
        if ($this->option('with-history') && $blocked->isNotEmpty()) {
            $this->newLine();
            $this->warn('--with-history will leave POS sales, orders and purchase orders holding their original totals while the lines naming these products are gone. Those documents will no longer add up, and past figures will move.');
        }

        $keep = $this->option('with-history') ? collect() : $withHistory;
        $delete = $ids->diff($keep);

        $this->newLine();
        $this->line("Will delete: <fg=green>{$delete->count()}</>   Will keep: <fg=yellow>{$keep->count()}</>");

        if ($keep->isNotEmpty()) {
            $this->line('  (kept because they have history — re-run with --with-history to remove them too)');
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->comment('DRY RUN — nothing changed. Add --force to apply.');
            return self::SUCCESS;
        }

        if ($delete->isEmpty()) {
            $this->info('Nothing to delete.');
            return self::SUCCESS;
        }

        // Deleted one model at a time on purpose. Product implements HasMedia,
        // and Spatie removes a product's image rows AND the files on disk from
        // the model's deleting event. A mass ->whereIn()->delete() skips model
        // events entirely, which would leave every image orphaned on the server.
        $deleted = 0;
        $bar = $this->output->createProgressBar($delete->count());
        $bar->start();

        foreach ($delete->chunk(100) as $chunk) {
            $c = $chunk->all();

            DB::transaction(function () use ($c, &$deleted, $bar) {
                foreach (self::DETACH as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->whereIn('product_id', $c)->delete();
                    }
                }

                // The restricting rows, cleared inside the same transaction so
                // the product delete below has nothing left holding it. Only
                // under --with-history: without it these products were filtered
                // out above and never reach here. Previously nothing cleared
                // them, so --with-history threw a foreign-key error on any
                // product that had been counted, sold at the till or procured —
                // and rolled the whole chunk back.
                if ($this->option('with-history')) {
                    foreach (array_keys(self::BLOCKERS) as $table) {
                        if (DB::getSchemaBuilder()->hasTable($table)) {
                            DB::table($table)->whereIn('product_id', $c)->delete();
                        }
                    }
                }

                foreach (Product::whereIn('id', $c)->cursor() as $product) {
                    $product->delete();
                    $deleted++;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();

        $this->newLine();
        $this->info("Deleted {$deleted} products for {$vendor->name}"
            . ($store !== null ? " at {$store->name}" : '') . '.');
        $this->line('Remaining: ' . Product::where('vendor_id', $vendor->id)
            ->when($store, fn ($q) => $q->where('store_id', $store->id))
            ->count());

        return self::SUCCESS;
    }

    private function countRows(string $table, $ids): int
    {
        return DB::getSchemaBuilder()->hasTable($table)
            ? DB::table($table)->whereIn('product_id', $ids)->count()
            : 0;
    }

    private function idsWithRows(string $table, $ids)
    {
        return DB::getSchemaBuilder()->hasTable($table)
            ? DB::table($table)->whereIn('product_id', $ids)->distinct()->pluck('product_id')
            : collect();
    }
}
