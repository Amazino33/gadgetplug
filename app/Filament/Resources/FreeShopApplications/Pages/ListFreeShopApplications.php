<?php

namespace App\Filament\Resources\FreeShopApplications\Pages;

use App\Filament\Resources\FreeShopApplications\FreeShopApplicationResource;
use App\Models\Vendor;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFreeShopApplications extends ListRecords
{
    protected static string $resource = FreeShopApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Vendor::STATUS_PENDING;
    }

    public function getTabs(): array
    {
        // One query for all four counts.
        $counts = Vendor::query()
            ->where('plan', Vendor::PLAN_FREE)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect([
            Vendor::STATUS_PENDING   => 'Pending',
            Vendor::STATUS_APPROVED  => 'Approved',
            Vendor::STATUS_REJECTED  => 'Rejected',
            Vendor::STATUS_SUSPENDED => 'Suspended',
        ])->mapWithKeys(fn (string $label, string $status) => [
            $status => Tab::make($label)
                ->badge((int) ($counts[$status] ?? 0))
                ->badgeColor($status === Vendor::STATUS_PENDING ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status)),
        ])->all();
    }
}
