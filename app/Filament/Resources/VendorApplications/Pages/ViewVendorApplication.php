<?php

namespace App\Filament\Resources\VendorApplications\Pages;

use App\Filament\Resources\VendorApplications\VendorApplicationResource;
use App\Models\Vendor;
use App\Models\VendorApplication;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewVendorApplication extends ViewRecord
{
    protected static string $resource = VendorApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve Application')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->form([
                    TextInput::make('admin_notes')
                        ->label('Welcome note (optional)')
                        ->placeholder('e.g. Welcome aboard! Your store is now live.'),
                ])
                ->action(function (array $data): void {
                    $record = $this->record;

                    // Slug auto-generated uniquely by spatie/laravel-sluggable
                    $vendor = Vendor::create([
                        'user_id'     => $record->user_id,
                        'name'        => $record->store_name,
                        'is_verified' => true,
                    ]);

                    $vendor->users()->syncWithoutDetaching([
                        $record->user_id => ['role' => 'owner'],
                    ]);

                    $record->update([
                        'status'      => 'approved',
                        'admin_notes' => $data['admin_notes'] ?? null,
                    ]);

                    $panelUrl = route('filament.vendor.home', ['tenant' => $vendor->slug]);

                    Notification::make()
                        ->title('Approved — ' . $record->store_name)
                        ->body('Vendor panel ready: ' . $panelUrl)
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'admin_notes']);
                })
                ->visible(fn() => $this->record->status === 'pending'),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([
                    Textarea::make('admin_notes')
                        ->label('Reason for rejection')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'status'      => 'rejected',
                        'admin_notes' => $data['admin_notes'],
                    ]);

                    Notification::make()->title('Application rejected')->warning()->send();
                    $this->refreshFormData(['status', 'admin_notes']);
                })
                ->visible(fn() => $this->record->status === 'pending'),
        ];
    }
}
