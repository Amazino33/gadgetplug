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
use Illuminate\Support\Facades\DB;

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

                    // Locked and re-checked inside a transaction, exactly as the
                    // table action does.
                    //
                    // This ran with neither. Creating the vendor committed on its
                    // own, so when a later step failed the vendor survived while
                    // the application stayed pending — and the next click made
                    // another one. That is how a single application ended up with
                    // duplicate vendors behind it. ->visible() is no guard here:
                    // it only hides the button after the page re-renders.
                    $vendor = DB::transaction(function () use ($record, $data) {
                        $locked = VendorApplication::whereKey($record->id)->lockForUpdate()->first();

                        if ($locked->status !== 'pending') {
                            return null;
                        }

                        // Slug auto-generated uniquely by spatie/laravel-sluggable
                        $vendor = Vendor::create([
                            'user_id'     => $locked->user_id,
                            'name'        => $locked->store_name,
                            'is_verified' => true,
                        ]);

                        // Membership only. vendor_users carried a `role` column
                        // until it was dropped for Spatie's per-vendor roles;
                        // writing to it here threw "Unknown column 'role'".
                        // Ownership lives on vendors.user_id, set above.
                        $vendor->users()->syncWithoutDetaching([$locked->user_id]);

                        $locked->update([
                            'status'      => 'approved',
                            'admin_notes' => $data['admin_notes'] ?? null,
                        ]);

                        return $vendor;
                    });

                    if (! $vendor) {
                        Notification::make()
                            ->title('Already processed')
                            ->body('This application was already approved or rejected.')
                            ->warning()
                            ->send();

                        return;
                    }

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
