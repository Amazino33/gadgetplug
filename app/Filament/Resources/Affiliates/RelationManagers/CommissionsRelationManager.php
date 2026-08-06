<?php

namespace App\Filament\Resources\Affiliates\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.reference')->label('Order')->searchable()->weight('bold'),
                TextColumn::make('amount')->label('Commission')->money('NGN')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'        => 'Pending',
                        'return_window'  => 'In Return Window',
                        'available'      => 'Available',
                        'rejected'       => 'Rejected',
                        default          => ucfirst($state),
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending'        => 'gray',
                        'return_window'  => 'warning',
                        'available'      => 'success',
                        'rejected'       => 'danger',
                        default          => 'gray',
                    }),

                TextColumn::make('rejected_reason')->label('Rejected Reason')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y, g:ia')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'       => 'Pending',
                        'return_window' => 'In Return Window',
                        'available'     => 'Available',
                        'rejected'      => 'Rejected',
                    ]),
            ])
            ->recordActions([
                Action::make('viewOrder')
                    ->label('View Order')
                    ->icon('heroicon-o-shopping-bag')
                    ->url(fn ($record) => \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->visible(fn ($record) => $record->order_id !== null),
            ]);
    }
}
