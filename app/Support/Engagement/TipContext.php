<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;

/**
 * Who is looking, in which panel, on which page. Everything TipEligibility
 * needs to know about the request, gathered once.
 */
final class TipContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?Vendor $vendor,
        public readonly string $panelId,
        public readonly ?string $routeName = null,
        public readonly ?int $storeId = null,
    ) {}

    /**
     * The current request's context, or null outside an authenticated panel.
     * $routeName lets a lazy component pass the page it was rendered on,
     * since by the time it loads the request is Livewire's, not the page's.
     */
    public static function current(?string $routeName = null): ?self
    {
        $user = auth()->user();
        $panel = filament()->getCurrentPanel();

        if (! $user instanceof User || ! $panel) {
            return null;
        }

        $tenant = filament()->getTenant();

        return new self(
            user: $user,
            vendor: $tenant instanceof Vendor ? $tenant : null,
            panelId: $panel->getId(),
            routeName: $routeName ?? request()->route()?->getName(),
            storeId: ActiveStore::currentId(),
        );
    }
}
