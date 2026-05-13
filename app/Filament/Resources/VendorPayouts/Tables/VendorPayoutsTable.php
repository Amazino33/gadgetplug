<?php

namespace App\Filament\Resources\VendorPayouts\Tables;

use App\Models\VendorPayout;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VendorPayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn($state) => '₦' . number_format($state, 2))
                    ->sortable(),

                TextColumn::make('bank_name')->label('Bank'),

                TextColumn::make('account_number')->label('Account No.'),

                TextColumn::make('account_name')->label('Account Name'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'info',
                        'paid'     => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('settled_at')
                    ->label('Settled')
                    ->date('d M Y')
                    ->placeholder('—'),

                TextColumn::make('admin_notes')
                    ->label('Note')
                    ->placeholder('—')
                    ->limit(50)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'approved' => 'Approved',
                        'paid'     => 'Paid',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn(VendorPayout $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Approve payout request?')
                    ->modalDescription(fn(VendorPayout $record) => 'Approve ₦' . number_format($record->amount, 2) . ' to ' . $record->account_name . ' (' . $record->bank_name . ')?')
                    ->action(function (VendorPayout $record) {
                        $record->update(['status' => 'approved']);
                        Notification::make()->title('Payout approved')->success()->send();
                    }),

                Action::make('mark_paid')
                    ->label('Mark Paid')
                    ->icon('heroicon-m-banknotes')
                    ->color('primary')
                    ->visible(fn(VendorPayout $record) => $record->status === 'approved')
                    ->form([
                        Textarea::make('admin_notes')
                            ->label('Payment reference / note')
                            ->placeholder('e.g. Transfer ref: TRX-12345')
                            ->required(),
                    ])
                    ->action(function (VendorPayout $record, array $data) {
                        $record->update([
                            'status'      => 'paid',
                            'admin_notes' => $data['admin_notes'],
                            'settled_at'  => now(),
                        ]);
                        Notification::make()->title('Marked as paid')->success()->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->visible(fn(VendorPayout $record) => in_array($record->status, ['pending', 'approved']))
                    ->form([
                        Textarea::make('admin_notes')
                            ->label('Reason for rejection')
                            ->required(),
                    ])
                    ->action(function (VendorPayout $record, array $data) {
                        $record->update([
                            'status'      => 'rejected',
                            'admin_notes' => $data['admin_notes'],
                        ]);
                        Notification::make()->title('Payout rejected')->danger()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
