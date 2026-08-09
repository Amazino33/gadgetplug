<?php

namespace App\Filament\Vendor\Resources\FinancialAccounts\Pages;

use App\Filament\Vendor\Resources\FinancialAccounts\FinancialAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancialAccounts extends ListRecords
{
    protected static string $resource = FinancialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
