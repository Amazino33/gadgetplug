<?php

namespace App\Filament\Resources\LogisticsCompanies\Pages;

use App\Filament\Resources\LogisticsCompanies\LogisticsCompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLogisticsCompanies extends ManageRecords
{
    protected static string $resource = LogisticsCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // Always a platform company: never tied to a vendor.
                ->mutateDataUsing(fn (array $data) => ['vendor_id' => null] + $data),
        ];
    }
}
