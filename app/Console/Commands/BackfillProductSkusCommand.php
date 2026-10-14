<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\SkuGenerator;
use Illuminate\Console\Command;

class BackfillProductSkusCommand extends Command
{
    protected $signature = 'products:backfill-sku {--vendor= : Only this vendor id} {--dry-run : Show what would be filled without saving}';

    protected $description = 'Give every product that has no SKU a generated one';

    public function handle(SkuGenerator $generator): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = Product::query()
            ->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))
            ->when($this->option('vendor'), fn ($q, $id) => $q->where('vendor_id', (int) $id));

        $total  = (clone $query)->count();
        $filled = 0;
        // Per vendor, so a dry run (which saves nothing) still previews
        // distinct numbers rather than the same one over and over.
        $offset = [];

        $this->info(($dry ? '[dry run] ' : '')."{$total} product(s) without a SKU.");

        $query->orderBy('id')->chunkById(200, function ($products) use ($generator, $dry, &$filled, &$offset) {
            foreach ($products as $product) {
                if ($dry) {
                    $skip = $offset[$product->vendor_id] ?? 0;
                    $sku  = $generator->for($product->vendor_id, $product->category_id, $product->name, $product->id, $skip);
                    $offset[$product->vendor_id] = $skip + 1;

                    if ($filled < 10) {
                        $this->line("  #{$product->id} {$product->name} → {$sku}");
                    }
                } else {
                    // Quietly: this is bookkeeping, not an edit anyone made, and
                    // it should not fire the observers or the activity log.
                    $product->forceFill(['sku' => $generator->generate($product)])->saveQuietly();
                }

                $filled++;
            }
        });

        $this->info($dry
            ? "Would fill {$filled}. Nothing was changed."
            : "Filled {$filled}.");

        return self::SUCCESS;
    }
}
