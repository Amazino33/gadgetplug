<?php

namespace App\Filament\Vendor\Resources\OrderItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class OrderItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.reference')
                    ->label('Order Ref')
                    ->searchable()
                    ->sortable(),
                
                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('order.customer_name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('order.customer_phone')
                    ->label('Phone')
                    ->searchable(),

                TextColumn::make('order.shipping_address')
                    ->label('Address')
                    ->wrap(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
