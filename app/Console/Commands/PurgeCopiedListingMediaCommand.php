<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Clears the photos that VendorLink publishing used to COPY onto resold
// listings, before listings started reading their supplier's photos live
// (Oct 2026). Nothing reads those copies any more — Product::catalogueSource()
// goes to the supplier — so they are dead files on disk.
//
// Dry run by default: lists what would go and deletes nothing. Only --force
// deletes, and only media in the product-images collection of products that
// are linked listings. Never touches a supplier's own photos.
class PurgeCopiedListingMediaCommand extends Command
{
    protected $signature = 'vendorlink:purge-copied-media
        {--force : Actually delete. Without it this is a dry run.}';

    protected $description = 'List (or, with --force, delete) photos copied onto VendorLink resold listings. Dry run by default.';

    public function handle(): int
    {
        $media = Media::query()
            ->where('model_type', (new Product)->getMorphClass())
            ->where('collection_name', 'product-images')
            ->whereIn('model_id', Product::query()->linked()->select('id'))
            ->orderBy('model_id')
            ->get();

        if ($media->isEmpty()) {
            $this->info('No copied photos on linked listings. Nothing to do.');

            return self::SUCCESS;
        }

        $listings = $media->groupBy('model_id');

        $this->table(
            ['Listing ID', 'Photos', 'Size'],
            $listings->map(fn ($items, $id) => [$id, $items->count(), $this->human($items->sum('size'))])->values()->all(),
        );

        $total = $this->human($media->sum('size'));

        if (! $this->option('force')) {
            $this->warn("DRY RUN — {$media->count()} photo(s) on {$listings->count()} listing(s), {$total}. Nothing was deleted.");
            $this->line('Run again with --force to delete them.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($media as $item) {
            // Through the model, so media-library removes the files and every
            // conversion along with the row.
            $item->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} copied photo(s) from {$listings->count()} listing(s), {$total}.");

        return self::SUCCESS;
    }

    private function human(int|float $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 1).' KB';
    }
}
