<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('image')
                    ->collection('category-image')
                    ->conversion('thumb')
                    ->label('')
                    ->circular(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug'),
                TextColumn::make('commission_rate')
                    ->label('Commission Rate')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) . '%' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reseller_discount')
                    ->label('Reseller Discount')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) . '%' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
