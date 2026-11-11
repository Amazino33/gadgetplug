<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use Filament\Resources\Pages\Page;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * The real panel pages a tip's button can point at, for the admin dropdown.
 *
 * Only GET pages that need nothing but the tenant: list pages and plain
 * pages. Create and edit screens and single-record pages are left out, since
 * a tip cannot know which record to open. The tip stores the route name and
 * DestinationResolver turns it into a URL per tenant when it is shown.
 */
class PanelDestinations
{
    public function __construct(private readonly DestinationResolver $resolver) {}

    /** @return array<string, array<string, string>> panel label => [route name => page label] */
    public function options(): array
    {
        $options = [];

        foreach (['vendor' => 'Vendor panel', 'admin' => 'Admin panel'] as $panel => $label) {
            $pages = [];

            foreach (Router::getRoutes()->getRoutes() as $route) {
                $name = $route->getName();

                if (! $name || ! str_starts_with($name, "filament.{$panel}.") || ! $this->isUsable($route)) {
                    continue;
                }

                $pages[$name] = $this->label($name);
            }

            asort($pages);
            $options[$label] = $pages;
        }

        // Pages outside the panel that a feature owns, under that feature's
        // own access rule (Sales Point).
        $outside = [];

        foreach (app(FeatureRegistry::class)->all() as $feature) {
            $route = $feature->destinationRoute();

            if ($route && Router::has($route) && $this->resolver->pageClass($route) === null) {
                $outside[$route] = $feature->defaultTitle();
            }
        }

        if ($outside !== []) {
            $options['Outside the panel'] = $outside;
        }

        return $options;
    }

    public function has(string $routeName): bool
    {
        foreach ($this->options() as $pages) {
            if (isset($pages[$routeName])) {
                return true;
            }
        }

        return false;
    }

    private function isUsable(Route $route): bool
    {
        if (! in_array('GET', $route->methods(), true)) {
            return false;
        }

        if (array_diff($route->parameterNames(), ['tenant']) !== []) {
            return false;
        }

        $name = (string) $route->getName();

        return $this->resolver->pageClass($name) !== null && ! $this->resolver->isFormPage($name);
    }

    private function label(string $routeName): string
    {
        $class = $this->resolver->pageClass($routeName);

        try {
            if ($class && is_subclass_of($class, Page::class)) {
                $resource = $class::getResource();

                return Str::of($resource::getPluralModelLabel())->ucfirst().' (list)';
            }

            if ($class && method_exists($class, 'getNavigationLabel')) {
                return (string) $class::getNavigationLabel();
            }
        } catch (\Throwable) {
            // Fall through to the route name: a label is a nicety.
        }

        return $routeName;
    }
}
