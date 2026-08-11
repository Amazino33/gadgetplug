<?php

namespace App\Http\Middleware;

use App\Services\Affiliate\ClickRewardService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts real page loads against the referral click the session arrived under,
 * so ClickRewardService can tell a bounce from an engaged visit.
 *
 * Runs after the response because "landed successfully on a page" is a claim
 * about the response, not the request — a 404 or a redirect is not a page the
 * visitor saw. Costs nothing for the overwhelming majority of traffic: with no
 * referral pointer in the session it is a single array lookup, and the pointer
 * is dropped as soon as its click resolves.
 */
class TrackAffiliateEngagement
{
    public function __construct(private ClickRewardService $clickRewards) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->countsAsPageView($request, $response)) {
            // Never let reward bookkeeping break the page the customer asked
            // for — a failure here costs an affiliate ₦2, a throw costs a sale.
            rescue(fn () => $this->clickRewards->registerPageView($request), report: true);
        }

        return $response;
    }

    private function countsAsPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $request->hasSession()) {
            return false;
        }

        // The /r/{code} hop is the click itself, not a page it led to.
        if ($request->routeIs('affiliate.click')) {
            return false;
        }

        // Livewire component updates are POSTs, but filter defensively: a
        // wire:navigate visit is a genuine page change and does count, while an
        // XHR round-trip inside one page does not.
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return false;
        }

        if (! $response->isSuccessful()) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
