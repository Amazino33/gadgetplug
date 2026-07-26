<?php

namespace App\Filament\Vendor\Resources\LogisticsCompanies\Pages;

use App\Filament\Vendor\Resources\LogisticsCompanies\LogisticsCompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLogisticsCompanies extends ManageRecords
{
    protected static string $resource = LogisticsCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
