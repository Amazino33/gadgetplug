<?php

namespace App\Filament\Resources\AffiliateApplications\Tables;

use App\Models\Affiliate;
use App\Models\AffiliateApplication;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AffiliateApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Applicant')
                    ->searchable()
                    ->description(fn (AffiliateApplication $r) => $r->user->email ?? ''),

                TextColumn::make('whatsapp')->label('WhatsApp')->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('created_at')->label('Applied')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->form([
                        TextInput::make('admin_notes')
                            ->label('Welcome note (optional)')
                            ->placeholder('e.g. Welcome aboard! Your referral link is ready.'),
                    ])
                    ->action(function (AffiliateApplication $record, array $data): void {
                        // Locked and re-checked inside the transaction — the
                        // ->visible() check below only hides the button after
                        // the page re-renders, it isn't a server-side guard, and
                        // approval creates a real Affiliate record that must
                        // never happen twice for one application.
                        $affiliate = DB::transaction(function () use ($record, $data) {
                            $locked = AffiliateApplication::whereKey($record->id)->lockForUpdate()->first();

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
                    })
                    ->visible(fn (AffiliateApplication $r) => $r->status === 'pending'),

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
                    ->action(function (AffiliateApplication $record, array $data): void {
                        $record->update([
                            'status'      => 'rejected',
                            'admin_notes' => $data['admin_notes'],
                        ]);

                        Notification::make()->title('Application rejected')->warning()->send();
                    })
                    ->visible(fn (AffiliateApplication $r) => $r->status === 'pending'),
            ]);
    }
}
