<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Feed\FeedCursor;
use App\Services\Feed\FeedQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The feed's next page.
 *
 * Kept lean on purpose — this is called on every scroll, on Nigerian mobile
 * data, so it returns only what a post renders and never a full product.
 */
class FeedController extends Controller
{
    public function __construct(private readonly FeedQuery $feed)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'cursor'   => 'nullable|string',
            'category' => 'nullable|integer|exists:categories,id',
            'search'   => 'nullable|string|max:100',
        ]);

        $page = $this->feed->page(
            seedBucket: FeedCursor::seedForSession(),
            cursor: $request->query('cursor'),
            categoryId: $request->integer('category') ?: null,
            search: $request->string('search')->trim()->value() ?: null,
        );

        return response()->json($page);
    }

    /**
     * The chips above the feed.
     *
     * Only categories that actually have something buyable in them, busiest
     * first — a chip that opens an empty feed is worse than no chip, because it
     * reads as the shop being broken rather than the category being quiet.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            // whereHas for "has any", not HAVING on the withCount alias: that
            // alias is a subquery rather than an aggregate, which SQLite
            // rejects outright — and the suite runs on SQLite.
            ->whereHas('products', fn ($q) => $q->visibleOnline()->inStockForSale())
            ->withCount(['products' => fn ($q) => $q->visibleOnline()->inStockForSale()])
            ->orderByDesc('products_count')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'slug'  => $c->slug,
                'count' => $c->products_count,
            ]);

        return response()->json(['categories' => $categories]);
    }
}
