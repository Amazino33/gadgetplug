<?php

namespace App\Filament\Resources\AffiliateCommissions\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\AffiliateCommission;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AffiliateCommissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('affiliate.code')->label('Affiliate')->searchable()->weight('bold'),
                TextColumn::make('affiliate.user.name')->label('Name')->searchable(),
                TextColumn::make('order.reference')->label('Order')->searchable(),
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
                SelectFilter::make('affiliate_id')
                    ->label('Affiliate')
                    ->relationship('affiliate', 'code'),
            ])
            ->recordActions([
                Action::make('viewOrder')
                    ->label('View Order')
                    ->icon('heroicon-o-shopping-bag')
                    ->url(fn (AffiliateCommission $record) => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->visible(fn (AffiliateCommission $record) => $record->order_id !== null),
            ]);
    }
}
