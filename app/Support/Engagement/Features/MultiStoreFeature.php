<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use Illuminate\Support\Facades\DB;

class MultiStoreFeature extends Feature
{
    public function key(): string
    {
        return 'multi_store';
    }

    public function defaultTitle(): string
    {
        return 'Run more than one branch';
    }

    public function defaultDescription(): string
    {
        return "Add a second shop and see every branch's sales and stock side by side.";
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.pages.stores-overview';
    }

    protected function usageSources(): array
    {
        return [
            // Every vendor is given a default store, so use starts with the
            // second one: any store with an older sibling.
            new UsageSource(
                query: fn () => DB::table('stores as s')->whereExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('stores as earlier')
                    ->whereColumn('earlier.vendor_id', 's.vendor_id')
                    ->whereColumn('earlier.id', '<', 's.id')),
                vendorColumn: 's.vendor_id',
                usedAtColumn: 's.created_at',
            ),
        ];
    }
}
