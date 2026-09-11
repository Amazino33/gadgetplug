<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Models\Category;

/**
 * The chips above the feed.
 *
 * Only categories that actually have something buyable in them, busiest first
 * — a chip that opens an empty feed is worse than no chip, because it reads as
 * the shop being broken rather than the category being quiet.
 *
 * "Buyable" has to mean exactly what the feed itself means by it, search term
 * included. A chip built from the whole catalogue while the feed is narrowed to
 * a search is the same broken promise in a subtler form: the category does hold
 * something, just nothing matching what was typed. So the search is applied
 * here through FeedQuery's own filter rather than a second copy of it.
 */
class FeedCategories
{
    /**
     * @return list<array{id: int, name: string, slug: string, count: int}>
     */
    public function forSearch(?string $search = null): array
    {
        // One closure for both clauses, so "has any" and "how many" can never
        // disagree about what counts.
        $buyable = fn ($query) => FeedQuery::applySearch(
            $query->visibleOnline()->inStockForSale(),
            $search,
        );

        return Category::query()
            ->where('is_active', true)
            // whereHas for "has any", not HAVING on the withCount alias: that
            // alias is a subquery rather than an aggregate, which SQLite
            // rejects outright — and the suite runs on SQLite.
            ->whereHas('products', $buyable)
            ->withCount(['products' => $buyable])
            ->orderByDesc('products_count')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $c) => [
                'id'    => (int) $c->id,
                'name'  => (string) $c->name,
                'slug'  => (string) $c->slug,
                'count' => (int) $c->products_count,
            ])
            ->all();
    }
}
