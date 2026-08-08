<?php

namespace App\Filament\Resources\AffiliateApplications\Pages;

use App\Filament\Resources\AffiliateApplications\AffiliateApplicationResource;
use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewAffiliateApplication extends ViewRecord
{
    protected static string $resource = AffiliateApplicationResource::class;

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
                        ->placeholder('e.g. Welcome aboard! Your referral link is ready.'),
                ])
                ->action(function (array $data): void {
                    // Locked and re-checked so a double-click can't create two
                    // affiliate records or overwrite an already-decided
                    // application — same guard the table row action below uses.
                    $affiliate = DB::transaction(function () use ($data) {
                        $locked = AffiliateApplication::whereKey($this->record->id)->lockForUpdate()->first();

                        if ($locked->status !== 'pending') {
                            return null;
                        }

                        $affiliate = Affiliate::findOrCreateForUser($locked->user);

                        $locked->update([
                            'status'      => 'approved',
                            'admin_notes' => $data['admin_notes'] ?? null,
                        ]);

                        return $affiliate;
                    });

                    if (! $affiliate) {
                        Notification::make()
                            ->title('Already processed')
                            ->body('This application was already approved or rejected.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Approved — referral code ' . $affiliate->code)
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'admin_notes']);
                })
                ->visible(fn () => $this->record->status === 'pending'),

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
                ->visible(fn () => $this->record->status === 'pending'),
        ];
    }
}
