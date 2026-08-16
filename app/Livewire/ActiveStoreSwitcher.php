<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ActiveStore;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Component;

// The store control in the panel topbar. Changing store here reloads the page
// rather than patching it in place: half the screen's data is store-scoped and
// a partial refresh would leave stale rows next to fresh ones.
class ActiveStoreSwitcher extends Component
{
    public function stores(): Collection
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (! $vendor || ! $user) {
            return collect();
        }

        return ActiveStore::accessibleFor($vendor, $user);
    }

    public function select(int $storeId): void
    {
        $vendor = filament()->getTenant();

        if (! ActiveStore::set($vendor, auth()->user(), $storeId)) {
            Notification::make()
                ->title('You do not have access to that store.')
                ->danger()
                ->send();

            return;
        }

        $this->redirect(request()->header('Referer') ?? url()->current(), navigate: false);
    }

    public function render()
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        $stores = $this->stores();

        return view('livewire.active-store-switcher', [
            'stores' => $stores,
            // Only worth showing when there is a choice to make; a single-store
            // vendor gets no ornament it cannot use.
            'active' => ($vendor && $user) ? ActiveStore::get($vendor, $user) : null,
            'hasChoice' => $stores->count() > 1,
        ]);
    }
}
