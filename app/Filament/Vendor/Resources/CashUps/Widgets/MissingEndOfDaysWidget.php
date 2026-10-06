<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\CashUps\Widgets;

use App\Models\Store;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\Cash\MissingEndOfDays;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Days opened or sold on that nobody has counted, above the End of Day records.
 *
 * The records list shows what was counted; a day that was never counted has no
 * record, so it could not appear there at all. This is where a manager sees it,
 * with whatever the cashier said when they put it off at the till.
 */
class MissingEndOfDaysWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.vendor.cash-ups.missing-end-of-days';

    /** @return Collection<int, array<string, mixed>> */
    public function getMissingDays(): Collection
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (! $vendor || ! $user) {
            return collect();
        }

        $days = app(MissingEndOfDays::class)->forBranch($vendor->id, ActiveStore::currentId());

        // A cashier reading their own history sees their own gaps, not the
        // whole shop's — the same line the records list draws.
        $reviewer = $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'receive_cash');

        if (! $reviewer) {
            $days = $days->where('cashier_id', $user->id)->values();
        }

        $names = User::whereIn('id', $days->pluck('cashier_id')->unique())->pluck('name', 'id');
        $stores = Store::whereIn('id', $days->pluck('store_id')->unique())->pluck('name', 'id');

        // Newest first: yesterday's gap is the one still worth chasing today.
        return $days
            ->map(fn (array $day) => $day + [
                'cashier_name' => $names[$day['cashier_id']] ?? 'Cashier #'.$day['cashier_id'],
                'store_name'   => $stores[$day['store_id']] ?? null,
            ])
            ->sortByDesc('business_date')
            ->values();
    }
}
