<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A page of the social feed.
 *
 * Deliberately a plain class rather than logic inside a Livewire component, so
 * the ordering and pagination can be tested without rendering anything.
 *
 * ORDERING — fresh between visits, fixed within one.
 *
 * Every product holds a stable feed_bucket (0–99, assigned once). A session
 * picks a starting bucket S, reads buckets >= S to the end, then wraps around
 * and reads < S. Two visits start in different places, so the feed does not
 * open with the same post twice; inside one visit the order never changes,
 * which is what lets a cursor walk it without repeating or skipping a post as
 * new products are published mid-scroll.
 *
 * The rotation is NOT expressed as (feed_bucket + seed) % 100 in the ORDER BY.
 * That is a runtime expression, so MySQL cannot use the index and would filesort
 * the whole catalogue on every page. Reading a plain range twice uses the index
 * both times.
 *
 * Cursor, never offset: offset re-counts from the top on each page and shifts
 * under you the moment a product is added or sells out, which shows a post twice
 * or drops one entirely.
 */
class FeedQuery
{
    public const PER_PAGE = 12;

    /** How many posts from one store may sit next to each other. */
    private const MAX_CONSECUTIVE_PER_VENDOR = 2;

    public function __construct(private readonly ProductInteractions $interactions)
    {
    }

    /**
     * @return array{posts: Collection, next_cursor: ?string, has_more: bool}
     */
    public function page(
        int $seedBucket,
        ?string $cursor = null,
        ?int $categoryId = null,
        ?string $search = null,
        int $perPage = self::PER_PAGE,
    ): array {
        $position = FeedCursor::decode($cursor) ?? FeedCursor::start($seedBucket);

        // One row wider than the page is all that is needed to know whether
        // more exists. The diversity pass works within the page rather than
        // across the window, so a bigger read would only be wasted rows.
        $window = $perPage + 1;

        $rows = $this->readWindow($position, $categoryId, $search, $window);

        // Ran out before the wrap: continue from bucket 0 up to the seed.
        if ($rows->count() < $window && ! $position['wrapped']) {
            $wrapped = FeedCursor::wrapPoint($seedBucket);
            $rows = $rows->concat(
                $this->readWindow($wrapped, $categoryId, $search, $window - $rows->count())
            );
        }

        // The page is the first N in READ order, and the cursor is that slice's
        // last row. Only then are those N reordered among themselves for
        // display.
        //
        // Reordering the whole window first and then cutting was wrong: the
        // diversity pass moves posts across the cut, so the last DISPLAYED post
        // was not the furthest READ one, the cursor went backwards, and the next
        // page re-served rows already shown. It only surfaced on some random
        // bucket distributions, which is exactly why it survived a green run.
        $slice = $rows->take($perPage);
        $last = $slice->last();
        $posts = $this->spaceOutVendors($slice);
        $hasMore = $rows->count() > $slice->count();

        return [
            'posts'       => $this->present($posts),
            'next_cursor' => $last && $hasMore ? FeedCursor::encode($last, $seedBucket) : null,
            'has_more'    => (bool) ($last && $hasMore),
        ];
    }

    /**
     * One keyset read, from a position onward.
     *
     * The three-part comparison is the keyset itself: a later bucket, or the
     * same bucket and an older post, or the same post-time and a lower id. Ties
     * are broken by id so two products published in the same second cannot both
     * sit on the boundary and be returned twice.
     */
    private function readWindow(array $position, ?int $categoryId, ?string $search, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return $this->base($categoryId, $search)
            ->where(function (Builder $q) use ($position) {
                $q->where('products.feed_bucket', '>', $position['bucket'])
                    ->orWhere(function (Builder $same) use ($position) {
                        $same->where('products.feed_bucket', $position['bucket'])
                            ->where(function (Builder $inner) use ($position) {
                                $inner->where('products.published_at', '<', $position['published_at'])
                                    ->orWhere(function (Builder $tie) use ($position) {
                                        $tie->where('products.published_at', $position['published_at'])
                                            ->where('products.id', '<', $position['id']);
                                    });
                            });
                    });
            })
            ->when($position['wrapped'], fn (Builder $q) => $q->where('products.feed_bucket', '<', $position['seed']))
            ->orderBy('products.feed_bucket')
            ->orderByDesc('products.published_at')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();
    }

    /**
     * What may appear at all: on the marketplace, in stock, published.
     *
     * Both scopes are the platform's existing ones rather than conditions
     * rewritten here — the feed must show exactly what the rest of the
     * storefront considers buyable, or a customer taps a post and finds it gone.
     */
    private function base(?int $categoryId, ?string $search): Builder
    {
        return Product::query()
            ->visibleOnline()
            ->inStockForSale()
            ->with(['vendor:id,name,slug', 'media'])
            ->when($categoryId, fn (Builder $q) => $q->where('products.category_id', $categoryId))
            ->when($search, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('products.name', 'like', $like)
                        ->orWhere('products.description', 'like', $like);
                });
            })
            ->select([
                'products.id', 'products.vendor_id', 'products.category_id', 'products.name',
                'products.slug', 'products.description', 'products.price', 'products.stock_quantity',
                'products.reserved_stock', 'products.source_product_id', 'products.supplier_link_id',
                'products.like_count', 'products.share_count', 'products.feed_bucket',
                'products.published_at',
            ]);
    }

    /**
     * Stop one store filling the screen.
     *
     * A run longer than the cap is not dropped — it is pushed further down and
     * another store's post pulled up, so nothing is lost from the feed, it just
     * arrives later. Falls back to appending the leftovers when nothing else is
     * available, because a short feed of one store beats an empty one.
     */
    private function spaceOutVendors(Collection $rows): Collection
    {
        $out = collect();
        $held = collect();
        $run = ['vendor' => null, 'count' => 0];

        $take = function (Product $product) use (&$out, &$run): void {
            $run = $product->vendor_id === $run['vendor']
                ? ['vendor' => $product->vendor_id, 'count' => $run['count'] + 1]
                : ['vendor' => $product->vendor_id, 'count' => 1];
            $out->push($product);
        };

        foreach ($rows as $product) {
            $wouldExceed = $product->vendor_id === $run['vendor']
                && $run['count'] >= self::MAX_CONSECUTIVE_PER_VENDOR;

            if ($wouldExceed) {
                $held->push($product);

                continue;
            }

            $take($product);

            // A held post from another store can go in now.
            $release = $held->first(fn (Product $p) => $p->vendor_id !== $run['vendor']);

            if ($release) {
                $held = $held->reject(fn (Product $p) => $p->id === $release->id)->values();
                $take($release);
            }
        }

        return $out->concat($held);
    }

    /** Only what a post renders — never a full product payload. */
    private function present(Collection $posts): Collection
    {
        $liked = $this->interactions->likedProductIds(
            $posts->pluck('id')->all(),
            auth()->user(),
            null,
        );

        return $posts->map(fn (Product $p) => [
            'id'          => $p->id,
            'name'        => $p->name,
            'slug'        => $p->slug,
            'caption'     => $p->description,
            'price'       => (float) $p->price,
            'url'         => route('product.show', $p->slug),
            // Falls back through feed -> preview -> placeholder, so a queue
            // that has not caught up serves a heavier image rather than a
            // broken one.
            'image'       => $p->feedImage(),
            'store'       => [
                'name' => $p->vendor?->name,
                'slug' => $p->vendor?->slug,
            ],
            'like_count'  => (int) $p->like_count,
            'share_count' => (int) $p->share_count,
            'liked'       => in_array($p->id, $liked, true),
        ])->values();
    }
}
