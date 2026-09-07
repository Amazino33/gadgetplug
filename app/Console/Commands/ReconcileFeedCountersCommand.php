<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds products.like_count / share_count from the interactions themselves.
 *
 * The counters are a cache so a feed page does not run a COUNT() per post. The
 * truth is product_interactions, and anything derived can drift — a failed
 * request between the row write and the increment, a row deleted by a cascade,
 * a restore from backup. This is what puts them back, and what proves whether
 * they were wrong.
 *
 * Read-only unless --fix is passed, so it can be run on production to answer
 * "have these drifted?" without changing anything.
 */
class ReconcileFeedCountersCommand extends Command
{
    protected $signature = 'feed:reconcile-counters
                            {--fix : Write the corrected counts rather than only reporting them}
                            {--chunk=500 : How many products to examine at a time}';

    protected $description = 'Check products.like_count / share_count against product_interactions';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $chunk = max(1, (int) $this->option('chunk'));

        $drifted = 0;
        $examined = 0;

        // Counted in one grouped pass rather than per product: a per-row COUNT
        // over a large catalogue is exactly the cost these columns exist to
        // avoid, and it would be strange to pay it in the tool that defends
        // them.
        $actual = DB::table('product_interactions')
            ->selectRaw('product_id, type, COUNT(*) as total')
            ->groupBy('product_id', 'type')
            ->get()
            ->groupBy('product_id');

        Product::query()
            ->select(['id', 'name', 'like_count', 'share_count'])
            ->chunkById($chunk, function ($products) use ($actual, $fix, &$drifted, &$examined): void {
                foreach ($products as $product) {
                    $examined++;

                    $rows = $actual->get($product->id, collect());
                    $likes = (int) ($rows->firstWhere('type', 'like')->total ?? 0);
                    $shares = (int) ($rows->firstWhere('type', 'share')->total ?? 0);

                    if ((int) $product->like_count === $likes && (int) $product->share_count === $shares) {
                        continue;
                    }

                    $drifted++;

                    $this->line(sprintf(
                        '  #%d %s — likes %d→%d, shares %d→%d',
                        $product->id,
                        \Illuminate\Support\Str::limit($product->name, 40),
                        $product->like_count, $likes,
                        $product->share_count, $shares,
                    ));

                    if ($fix) {
                        // updateQuietly: a counter correction is bookkeeping,
                        // not something a vendor did, and should not appear in
                        // the product's activity log as an edit.
                        $product->updateQuietly(['like_count' => $likes, 'share_count' => $shares]);
                    }
                }
            });

        $this->newLine();

        if ($drifted === 0) {
            $this->info("Every counter agrees with the interactions behind it ({$examined} products).");

            return self::SUCCESS;
        }

        $fix
            ? $this->info("Corrected {$drifted} of {$examined} products.")
            : $this->warn("{$drifted} of {$examined} products have drifted. Re-run with --fix to correct them.");

        return self::SUCCESS;
    }
}
