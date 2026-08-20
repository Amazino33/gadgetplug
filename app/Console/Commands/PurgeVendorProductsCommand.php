<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
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
        {vendor : Vendor id or slug}
        {--force : Actually delete (default is a dry run)}
        {--with-history : Also delete products that have sales history (DESTROYS order lines and ledger rows)}';

    protected $description = "Delete all of a vendor's products, reporting exactly what goes with them";

    /** Cascades silently when a product is deleted — the dangerous ones. */
    private const CASCADES = [
        'order_items'                 => 'online order lines',
        'inventory_ledgers'           => 'stock ledger entries',
        'blind_count_entries'         => 'count entries',
        'audit_sessions'              => 'audit sessions',
        'stock_accountability_entries'=> 'accountability entries',
    ];

    /** Blocks the delete with a foreign-key error instead of cascading. */
    private const BLOCKERS = [
        'pos_sale_items'   => 'POS sale lines',
        'procurement_items'=> 'procurement lines',
    ];

    /** Safe to clear first — no history value. */
    private const DETACH = ['wishlists', 'product_tag', 'product_store_stock', 'product_images'];

    public function handle(): int
    {
        $needle = trim((string) $this->argument('vendor'));

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

        $ids = Product::where('vendor_id', $vendor->id)->pluck('id');

        $this->newLine();
        $this->line("Vendor:   <fg=cyan>{$vendor->name}</> (id {$vendor->id}, slug {$vendor->slug})");
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
                $this->line("  <fg=red>{$rows->count()}</> products have " . self::BLOCKERS[$table] . " ({$table}) — these BLOCK deletion");
            }
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
        $this->info("Deleted {$deleted} products for {$vendor->name}.");
        $this->line('Remaining: ' . Product::where('vendor_id', $vendor->id)->count());

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
