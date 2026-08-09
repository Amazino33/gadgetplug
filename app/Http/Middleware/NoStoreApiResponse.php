<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The POS API has no cache headers of its own, so a GET response is fair game
// for any intermediary that decides to cache it — notably the data-saving
// transparent proxies several Nigerian mobile carriers run, which readily
// cache small JSON GET responses on 2G/3G links. That silently returns a
// stale (often empty) list on the next read, with no error to catch: a
// cashier suspends a sale, and it's never seen again on that terminal.
class NoStoreApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
