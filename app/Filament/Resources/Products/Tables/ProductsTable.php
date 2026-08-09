<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Vendor;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('vendor.name')->label('Store')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->sortable(),
                TextColumn::make('price')->label('Price')->money('NGN')->sortable(),
                TextColumn::make('commission_rate')
                    ->label('Rate Override')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) . '%' : '—')
                    ->badge()
                    ->color(fn ($state) => $state !== null ? 'success' : 'gray'),
                TextColumn::make('reseller_discount')
                    ->label('Discount Override')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) . '%' : '—')
                    ->badge()
                    ->color(fn ($state) => $state !== null ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Store')
                    ->options(fn () => Vendor::pluck('name', 'id'))
                    ->searchable(),
                TernaryFilter::make('commission_rate')
                    ->label('Has Rate Override')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('commission_rate'),
                        false: fn ($query) => $query->whereNull('commission_rate'),
                    ),
                TernaryFilter::make('reseller_discount')
                    ->label('Has Discount Override')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('reseller_discount'),
                        false: fn ($query) => $query->whereNull('reseller_discount'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->label('Edit'),
            ]);
    }
}
