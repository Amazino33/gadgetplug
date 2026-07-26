<?php

namespace App\Filament\Vendor\Resources\MessageTemplates\Pages;

use App\Filament\Vendor\Resources\MessageTemplates\MessageTemplateResource;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageMessageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDefaults')
                ->label('Seed Default Templates')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Adds a starter set of customer & rider message templates. Existing templates with the same key are left untouched.')
                ->visible(fn () => static::getResource()::getModel()::where('vendor_id', filament()->getTenant()->id)->count() === 0)
                ->action(function (): void {
                    MessageTemplateSeeder::forVendor(filament()->getTenant());

                    Notification::make()
                        ->title('Default templates added')
                        ->success()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
