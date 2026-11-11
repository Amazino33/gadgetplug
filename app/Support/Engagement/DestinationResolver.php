<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\User;
use App\Models\Vendor;
use Filament\Pages\BasePage;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Throwable;

/**
 * Turns a stored destination (route name + params) into a URL for one tenant,
 * and answers whether the signed-in user may open it.
 *
 * Access is Filament's own check, the same one the page runs on mount: a
 * resource page needs its resource's canAccess() and its own; a plain page
 * needs its canAccess(). A route outside the panel is allowed only when a
 * registered feature owns it (Sales Point's pos.vendor), and then that
 * feature's own access rule decides. Anything else is refused.
 */
class DestinationResolver
{
    public function url(string $routeName, array $params, ?Vendor $vendor): ?string
    {
        $route = $this->route($routeName);

        if (! $route) {
            return null;
        }

        if (in_array('tenant', $route->parameterNames(), true)) {
            if (! $vendor) {
                return null;
            }

            $params = ['tenant' => $vendor->slug] + $params;
        }

        // Routes outside the panel bind the business as {vendor:slug}.
        if (in_array('vendor', $route->parameterNames(), true)) {
            if (! $vendor) {
                return null;
            }

            $params = ['vendor' => $vendor->slug] + $params;
        }

        try {
            return route($routeName, $params);
        } catch (UrlGenerationException) {
            // A route needing a parameter the tip does not supply, such as a
            // single record: better no button than a broken one.
            return null;
        }
    }

    /**
     * Whether $user can open the page behind $routeName at $vendor.
     *
     * Filament's canAccess() reads the signed-in user and the current tenant,
     * so this fails closed when asked about anyone or anywhere else: it never
     * answers "yes" on the strength of a different person's permissions.
     */
    public function canAccess(string $routeName, User $user, ?Vendor $vendor): bool
    {
        if ((int) auth()->id() !== (int) $user->id) {
            return false;
        }

        $tenant = filament()->getTenant();

        if (($tenant?->getKey()) !== ($vendor?->getKey())) {
            return false;
        }

        $class = $this->pageClass($routeName);

        try {
            if ($class === null) {
                $owner = app(FeatureRegistry::class)->forRoute($routeName);

                return $owner !== null && $vendor !== null && $owner->canAccess($user, $vendor);
            }

            if (is_subclass_of($class, ResourcePage::class)) {
                return $class::getResource()::canAccess() && $class::canAccess();
            }

            return $class::canAccess();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** The Filament page class behind a route, or null if it is not one. */
    public function pageClass(?string $routeName): ?string
    {
        $route = $routeName ? $this->route($routeName) : null;
        $class = $route?->getControllerClass();

        if (! $class || ! class_exists($class) || ! method_exists($class, 'canAccess')) {
            return null;
        }

        return is_subclass_of($class, BasePage::class) ? $class : null;
    }

    /** Create and edit screens, where a pop-up would interrupt someone mid-form. */
    public function isFormPage(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        if (str_ends_with($routeName, '.create') || str_ends_with($routeName, '.edit')) {
            return true;
        }

        $class = $this->pageClass($routeName);

        return $class !== null
            && (is_subclass_of($class, CreateRecord::class) || is_subclass_of($class, EditRecord::class));
    }

    private function route(string $name): ?Route
    {
        return Router::getRoutes()->getByName($name);
    }
}
