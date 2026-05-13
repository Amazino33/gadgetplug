<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn() => match($this->record->status) {
                            'pending', 'confirmed' => [
                                'shipped'   => 'Mark as Dispatched',
                                'cancelled' => 'Cancel Order',
                            ],
                            'paid' => [
                                'shipped'   => 'Mark as Dispatched',
                                'cancelled' => 'Cancel Order',
                            ],
                            'shipped' => [
                                'delivered' => 'Mark as Delivered',
                                'cancelled' => 'Cancel Order',
                            ],
                            default => [],
                        })
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->update(['status' => $data['status']]);
                    $this->refreshFormData(['status']);
                })
                ->visible(fn() => !in_array($this->record->status, ['delivered', 'cancelled', 'paid_but_failed_stock'])),
        ];
    }
}
