<?php

namespace App\Filament\Vendor\Resources\Customers\Pages;

use App\Filament\Vendor\Pages\ImportCustomers;
use App\Filament\Vendor\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomers extends ManageRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The shop is set here, never taken from the form: a customer
            // always belongs to the shop whose panel added them.
            CreateAction::make()
                ->mutateDataUsing(fn (array $data) => ['vendor_id' => filament()->getTenant()->id] + $data),

            // The customer list is where someone looks for the import, so the
            // way in is here as well as in the menu.
            Action::make('import')
                ->label('Import customers')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn () => ImportCustomers::getUrl())
                ->visible(fn () => ImportCustomers::canAccess()),
        ];
    }
}
