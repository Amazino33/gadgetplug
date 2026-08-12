<?php

namespace App\Filament\Resources\AffiliateReachBands\Pages;

use App\Filament\Resources\AffiliateReachBands\AffiliateReachBandResource;
use App\Models\AffiliateReachBand;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ManageAffiliateReachBands extends ManageRecords
{
    protected static string $resource = AffiliateReachBandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),

                TextColumn::make('min_reach')
                    ->label('Range')
                    ->formatStateUsing(fn ($state, AffiliateReachBand $record) => number_format($state)
                        . ' – ' . ($record->max_reach === null ? '∞' : number_format($record->max_reach)))
                    ->sortable(),

                TextColumn::make('points')->label('Points')->alignCenter()->weight('bold')->sortable(),
                TextColumn::make('sort_order')->label('Order')->alignCenter()->sortable()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('min_reach')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
