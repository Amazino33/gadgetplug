<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Undoes a SKU-collision overwrite: a later import whose rows happened to share
 * SKUs with existing products, silently renaming and repricing them instead of
 * creating anything new.
 *
 * This is not the same failure as products landing in the wrong store — that
 * command (products:move-store) relocates rows that are correctly identified
 * but sitting in the wrong place. This one repairs rows whose identity itself
 * was destroyed: a real product ("A1481", a genuine Itel item) had its name,
 * price and cost silently replaced by an unrelated row from a different file,
 * purely because both rows happened to use SKU "1". The row was never created
 * fresh — Product::update() ran against the existing one, so nothing in the
 * normal product list ever showed two rows to compare; the original simply
 * became the new one under the same primary key.
 *
 * The activity log is what makes this recoverable at all: LogsActivity records
 * the pre-overwrite values (name, price, cost_price) with a timestamp, so the
 * true original state can be read back rather than guessed at.
 */
class RestoreOverwrittenProductsCommand extends Command
{
    protected $signature = 'products:restore-overwritten
                            {vendor : Vendor id}
                            {around : Timestamp of the bad import that caused the overwrite, e.g. "2026-08-22 14:59:40"}
                            {--within=120 : Seconds either side of that timestamp counted as the same event}
                            {--store= : Only products currently in this store}
                            {--brand= : Only products currently carrying this (corrupted) brand}
                            {--to-store= : Store id to move the restored products into}
                            {--to-brand= : Brand to set on the restored products}
                            {--force : Actually restore them. Without this, only reports what would change.}';

    protected $description = 'Restore products whose name/price/cost were overwritten by a later import matching on SKU';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendor');
        $storeId  = $this->option('store');
        $brand    = $this->option('brand');
        $toStore  = $this->option('to-store');
        $toBrand  = $this->option('to-brand');

        $around = \Illuminate\Support\Carbon::parse($this->argument('around'));
        $within = (int) $this->option('within');

        $products = Product::where('vendor_id', $vendorId)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($brand, fn ($q) => $q->where('brand', $brand))
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->info('Nothing matches — no products to restore.');

            return self::SUCCESS;
        }

        $this->line("{$products->count()} product(s) match. Checking each for a prior overwrite...");

        $plan = [];

        foreach ($products as $product) {
            $overwrite = $this->findOverwriteEvent($product, $around, $within);

            if ($overwrite === null) {
                continue;
            }

            $old = $overwrite->properties['old'] ?? [];

            // Only restore fields the overwrite actually touched. A row where
            // the collision only changed price, not name, should not have a
            // name written back that was never actually altered.
            $restore = array_intersect_key($old, array_flip(['name', 'price', 'cost_price', 'category_id']));

            if ($restore === []) {
                continue;
            }

            $plan[] = [
                'product'    => $product,
                'restore'    => $restore,
                'overwrite'  => $overwrite,
            ];
        }

        if ($plan === []) {
            $this->info('No overwrite events found for any matched product — nothing to restore.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(count($plan).' product(s) show a prior overwrite:');

        $rows = collect($plan)->take(20)->map(function (array $p) {
            /** @var Product $product */
            $product = $p['product'];

            return [
                $product->id,
                $product->name,
                $p['restore']['name'] ?? $product->name,
                $product->price,
                $p['restore']['price'] ?? '—',
            ];
        })->all();

        $this->table(['ID', 'Current name', 'Restores to', 'Current price', 'Restores to'], $rows);

        if (count($plan) > 20) {
            $this->line('  … and '.(count($plan) - 20).' more.');
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run — nothing restored. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $restored = 0;
        $failed   = [];

        foreach ($plan as $p) {
            /** @var Product $product */
            $product = $p['product'];

            try {
                DB::transaction(function () use ($product, $p, $toStore, $toBrand) {
                    $attributes = $p['restore'];

                    if ($toBrand) {
                        $attributes['brand'] = $toBrand;
                    }

                    // store_id changed last: ProductObserver::updating() guards
                    // a store move while stock is reserved, and that guard
                    // should see the restored identity, not the overwritten one,
                    // if it ever needs to name the product in its error.
                    $product->fill($attributes)->save();

                    if ($toStore) {
                        $product->update(['store_id' => (int) $toStore]);
                    }
                });

                $restored++;
            } catch (LogicException $e) {
                $failed[] = ['id' => $product->id, 'name' => $product->name, 'reason' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed[] = ['id' => $product->id, 'name' => $product->name, 'reason' => $e->getMessage()];
            }
        }

        $this->info("{$restored} product(s) restored.");

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' product(s) could not be fully restored:');

            foreach ($failed as $f) {
                $this->line("  #{$f['id']} {$f['name']}: {$f['reason']}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * The activity-log entry that overwrote this product's identity: the
     * 'updated' event closest to the known bad import's timestamp.
     *
     * Anchored to a specific, evidenced moment rather than guessed at by which
     * fields changed. A field-based guess ("the update that touched name")
     * risks two failure modes: missing a genuine overwrite that happened not
     * to touch name, and mistaking an unrelated, legitimate later edit for the
     * collision. Knowing exactly when the bad import ran removes both risks —
     * every affected row was touched within seconds of that one timestamp,
     * confirmed from the import log itself.
     */
    private function findOverwriteEvent(Product $product, \Illuminate\Support\Carbon $around, int $withinSeconds): ?Activity
    {
        return Activity::where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('event', 'updated')
            ->whereBetween('created_at', [
                $around->copy()->subSeconds($withinSeconds),
                $around->copy()->addSeconds($withinSeconds),
            ])
            ->orderBy('created_at')
            ->first();
    }
}
