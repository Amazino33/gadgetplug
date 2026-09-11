<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Feed\FeedCategories;
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
    public function __construct(
        private readonly FeedQuery $feed,
        private readonly FeedCategories $categories,
    ) {
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
     * The chips above the feed, for the search now in force.
     *
     * Re-asked whenever the reader changes the filter, not on every page of the
     * scroll — the chips only go stale when the thing they describe changes.
     */
    public function categories(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        return response()->json([
            'categories' => $this->categories->forSearch(
                $request->string('search')->trim()->value() ?: null,
            ),
        ]);
    }
}
