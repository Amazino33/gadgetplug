<?php

namespace App\Filament\Resources\AffiliateLevels\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AffiliateLevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('Rank')->sortable(),
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('target')->label('Target')->money('NGN')->sortable(),
                TextColumn::make('rate_value')->label('Rate Multiplier')->formatStateUsing(fn ($state) => number_format((float) $state, 2) . '×'),
                TextColumn::make('affiliates_count')->label('Affiliates')->counts('affiliates')->alignCenter(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
