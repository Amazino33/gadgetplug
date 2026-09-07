<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Generates the feed's WebP conversions for products that predate them.
 *
 * Also the only honest way to find out whether the queue is running. The feed
 * conversions are queued, so a host with no worker generates nothing and says
 * nothing — the feed simply keeps serving heavy 'preview' images for ever, which
 * looks like it is working. Running this with --status answers the question
 * directly: if the missing count does not fall after a backfill, there is no
 * worker.
 */
class BackfillFeedImagesCommand extends Command
{
    protected $signature = 'feed:backfill-images
                            {--status : Only report how many are missing, generate nothing}
                            {--chunk=50 : Products per batch}
                            {--sync : Generate in-process instead of queueing, for a host with no worker}';

    protected $description = 'Generate the feed WebP conversions for existing product images';

    public function handle(): int
    {
        $missing = $this->countMissing();
        $total = Media::where('model_type', Product::class)
            ->where('collection_name', 'product-images')
            ->count();

        $this->line("Product images: {$total}");
        $this->line("Missing a feed conversion: {$missing}");

        if ($this->option('status')) {
            $this->newLine();
            $this->line('Run without --status to generate them.');
            $this->comment('If this number does not fall afterwards, no queue worker is processing them.');

            return self::SUCCESS;
        }

        if ($missing === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $chunk = max(1, (int) $this->option('chunk'));

        // The conversions declare themselves queued, and createDerivedFiles()
        // honours that — so forcing them inline is a matter of pointing the
        // queue at the sync connection for this command, not of calling a
        // different method. That keeps one code path for both modes.
        if ($sync) {
            config(['queue.default' => 'sync']);
        }
        $done = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($missing);
        $bar->start();

        Media::query()
            ->where('model_type', Product::class)
            ->where('collection_name', 'product-images')
            ->chunkById($chunk, function ($batch) use (&$done, &$failed, $bar, $sync): void {
                foreach ($batch as $media) {
                    if ($media->hasGeneratedConversion('feed')) {
                        continue;
                    }

                    try {
                        // Chunked and one at a time on purpose: regenerating a
                        // whole catalogue of images at once is exactly how a
                        // backfill takes a shared host down.
                        //
                        // onlyMissing so a re-run after a partial pass costs
                        // nothing for the images already done.
                        app(\Spatie\MediaLibrary\Conversions\FileManipulator::class)
                            ->createDerivedFiles($media, ['feed', 'feed_small'], onlyMissing: true);

                        $done++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->warn("  media #{$media->id}: ".$e->getMessage());
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed {$done}".($failed ? ", {$failed} failed" : ''));

        if (! $sync) {
            $this->comment('Queued. Re-run with --status in a minute; if the missing count has not fallen, no worker is running — use --sync.');
        }

        return self::SUCCESS;
    }

    private function countMissing(): int
    {
        return Media::query()
            ->where('model_type', Product::class)
            ->where('collection_name', 'product-images')
            ->get(['id', 'generated_conversions'])
            ->filter(fn (Media $m) => ! $m->hasGeneratedConversion('feed'))
            ->count();
    }
}
