<?php

namespace App\Http\Middleware;

use App\Filament\Vendor\FreeShopNavigation;
use App\Models\Vendor;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a free-plan shop may open in the vendor panel.
 *
 * Does nothing at all unless the tenant's plan is 'free' — every vendor that
 * existed before self-signup is 'paid' and passes straight through, so the
 * supermarket and everyone else see the panel exactly as before.
 *
 * For a free shop, by status:
 *   suspended → nothing but the suspended page
 *   rejected  → only the edit-and-resubmit form (outside the panel)
 *   pending / approved → the dashboard, Products and Shop Profile; any other
 *               panel URL typed in directly is refused, not just hidden
 *
 * Registered as persistent tenant middleware, so Livewire's update requests
 * are checked against the page they came from as well.
 */
class EnforceFreePlan
{
    /** Panel routes a free shop may use. Route names, so a renamed URL keeps working. */
    public const ALLOWED_ROUTES = [
        'filament.vendor.home',   // /plug/{tenant} — only redirects to the dashboard
        'filament.vendor.pages.dashboard',
        'filament.vendor.pages.shop-profile',
        'filament.vendor.resources.products.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $vendor = filament()->getTenant();
        $user   = $request->user();

        if (! $vendor instanceof Vendor || ! $vendor->isFreePlan() || ! $user || $user->isSuperAdmin()) {
            FreeShopNavigation::reset();

            return $next($request);
        }

        $quiet = $request->expectsJson() || $request->hasHeader('X-Livewire');

        if ($vendor->hasStatus(Vendor::STATUS_SUSPENDED)) {
            if ($quiet) {
                abort(403, 'This shop has been suspended.');
            }

            return response()->view('vendor.free-shop-suspended', ['vendor' => $vendor], 403);
        }

        if ($vendor->hasStatus(Vendor::STATUS_REJECTED)) {
            if ($quiet) {
                abort(403, 'This application was not approved. Edit and resubmit it to continue.');
            }

            return redirect()->route('free-shop.application.edit');
        }

        if (! $request->routeIs(...self::ALLOWED_ROUTES)) {
            if ($quiet || ! $request->isMethod('GET')) {
                abort(403, 'That is not part of the free plan.');
            }

            Notification::make()
                ->title('Not part of the free plan')
                ->body('Your free shop includes Products and Shop Profile.')
                ->warning()
                ->send();

            return redirect()->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
        }

        FreeShopNavigation::apply();

        return $next($request);
    }
}
