<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Activitylog\Models\Activity;

// Records what changed in a product's photos, with who changed it.
//
// Product's own LogsActivity only watches columns, and adding, removing or
// reordering media never touches a product column — so without this, the
// back-office image work would leave no trace at all. One entry per save, with
// the diff, rather than one per media row.
class ProductMediaActivity
{
    public const COLLECTION = 'product-images';

    /**
     * The product's photos as they are right now, in display order.
     *
     * @return array<int, array{id: int, file: string}>
     */
    public static function snapshot(Product $product): array
    {
        return $product->media()
            ->where('collection_name', self::COLLECTION)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get(['id', 'file_name'])
            ->map(fn ($media) => ['id' => (int) $media->id, 'file' => (string) $media->file_name])
            ->all();
    }

    /**
     * Log the difference between two snapshots. Returns null when nothing changed.
     *
     * @param  array<int, array{id: int, file: string}>  $before
     * @param  array<int, array{id: int, file: string}>  $after
     */
    public static function log(Product $product, array $before, array $after, ?Authenticatable $causer): ?Activity
    {
        $beforeIds = array_column($before, 'id');
        $afterIds  = array_column($after, 'id');

        $added   = array_values(array_filter($after, fn ($m) => ! in_array($m['id'], $beforeIds, true)));
        $removed = array_values(array_filter($before, fn ($m) => ! in_array($m['id'], $afterIds, true)));

        // Reordered: the photos present both before and after no longer sit in
        // the same relative order. Additions and removals alone don't count.
        $kept       = array_values(array_intersect($beforeIds, $afterIds));
        $keptAfter  = array_values(array_intersect($afterIds, $beforeIds));
        $reordered  = $kept !== $keptAfter;

        $primaryChanged = ($beforeIds[0] ?? null) !== ($afterIds[0] ?? null);

        if ($added === [] && $removed === [] && ! $reordered) {
            return null;
        }

        return activity('catalogue')
            ->performedOn($product)
            ->causedBy($causer)
            ->event('media_updated')
            ->withProperties([
                'added'           => $added,
                'removed'         => $removed,
                'reordered'       => $reordered,
                'primary_changed' => $primaryChanged,
                'before'          => $beforeIds,
                'after'           => $afterIds,
            ])
            ->tap(function (Activity $activity) use ($product): void {
                // Same column Product::tapActivity fills, so the entry shows up
                // in that vendor's own activity log.
                $activity->vendor_id = $product->vendor_id;
            })
            ->log('Product photos updated');
    }
}
