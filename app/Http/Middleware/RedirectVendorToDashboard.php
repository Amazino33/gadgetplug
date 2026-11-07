<?php

namespace App\Http\Middleware;

use App\Http\Responses\LoginResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectVendorToDashboard
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $user = $request->user();

            if ($user->isSuperAdmin() || $user->vendors()->isNotEmpty()) {
                return redirect(LoginResponse::resolveDashboardUrl($user));
            }
        }

        return $next($request);
    }
}
