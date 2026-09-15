<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectVendorToDashboard
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $user = $request->user();
            
            if ($user->isSuperAdmin() || $user->vendors()->isNotEmpty()) {
                return redirect(\App\Http\Responses\LoginResponse::resolveDashboardUrl($user));
            }
        }

        return $next($request);
    }
}
