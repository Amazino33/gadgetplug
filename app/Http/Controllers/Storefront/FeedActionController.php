<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeviceToken;
use App\Listeners\ClaimGuestFeedActivity;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Feed\ProductInteractions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * What a tap on a post actually does.
 *
 * Every rule here is enforced server-side even though the interface already
 * applies it. The UI gate is a courtesy to the customer; this is the control.
 *
 * The product is always re-read from the marketplace-visible set rather than
 * taken on trust from the id posted — a hidden or unpublished product must not
 * be likeable, saveable or buyable just because somebody kept its id.
 */
class FeedActionController extends Controller
{
    public function __construct(private readonly ProductInteractions $interactions)
    {
    }

    /** A like. Guests may, by device token — it is cheap and reveals nothing. */
    public function like(Request $request, Product $product): JsonResponse
    {
        $product = $this->buyable($product);

        if (! $product) {
            return response()->json(['message' => 'That product is no longer available.'], 404);
        }

        try {
            $liked = $this->interactions->toggleLike(
                $product,
                $request->user(),
                EnsureDeviceToken::current($request),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The count comes back from the server so an optimistic UI that guessed
        // wrong — two devices, a double tap, a lost request — corrects itself
        // rather than drifting further with every tap.
        return response()->json([
            'liked'      => $liked,
            'like_count' => (int) $product->fresh()->like_count,
        ]);
    }

    /**
     * A save. Signed-in only.
     *
     * A guest is not refused outright — their intent is stashed and replayed
     * after login, so the gate is a step rather than a dead end. See
     * ClaimGuestFeedActivity.
     */
    public function save(Request $request, Product $product): JsonResponse
    {
        $product = $this->buyable($product);

        if (! $product) {
            return response()->json(['message' => 'That product is no longer available.'], 404);
        }

        if (! $request->user()) {
            session([ClaimGuestFeedActivity::PENDING_SAVE => [
                'action'     => 'save',
                'product_id' => $product->id,
            ]]);

            return response()->json([
                'requires_login' => true,
                'login_url'      => route('login'),
            ], 401);
        }

        $saved = $this->interactions->hasSaved($product, $request->user())
            ? $this->interactions->unsave($product, $request->user())
            : $this->interactions->save($product, $request->user());

        return response()->json(['saved' => $saved]);
    }

    /** A share. Logged as an event — the same product shared twice is two shares. */
    public function share(Request $request, Product $product): JsonResponse
    {
        $product = $this->buyable($product);

        if (! $product) {
            return response()->json(['message' => 'That product is no longer available.'], 404);
        }

        $this->interactions->logShare(
            $product,
            $request->user(),
            EnsureDeviceToken::current($request),
        );

        return response()->json([
            'share_count' => (int) $product->fresh()->share_count,
            // The deep link the OG tags already render a preview for.
            'url'         => route('product.show', $product->slug),
        ]);
    }

    /**
     * Buy Now — into the cart and straight to checkout.
     *
     * Goes through CartService like every other add, so the stock guard, the
     * vendor's online-sales gate and the combined-stock rules all apply exactly
     * as they do from the product page. A separate path here would be a second
     * set of rules to keep in step.
     *
     * A redirect rather than JSON: this ends on the checkout page, and letting
     * the browser follow it keeps the session and the cart cookie behaving as
     * they do everywhere else.
     */
    public function buyNow(Request $request, Product $product): RedirectResponse
    {
        $product = $this->buyable($product);

        if (! $product) {
            return redirect()->route('home')->with('error', 'That product is no longer available.');
        }

        if (! app(CartService::class)->add($product)) {
            // Sold out between the feed being rendered and the tap. The product
            // page says so properly rather than dropping them on a checkout
            // with nothing in it.
            return redirect()->route('product.show', $product->slug);
        }

        return redirect()->route('checkout');
    }

    /**
     * The product, only if the marketplace would show it.
     *
     * visibleOnline rather than a plain lookup: an id kept from a since-hidden
     * vendor, or a product pulled from sale, must not still be actionable.
     */
    private function buyable(Product $product): ?Product
    {
        return Product::visibleOnline()->whereKey($product->id)->first();
    }
}
