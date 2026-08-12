<?php

namespace App\Filament\Resources\MarketingMaterials\Pages;

use App\Filament\Resources\MarketingMaterials\MarketingMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ManageMarketingMaterials extends ManageRecords
{
    protected static string $resource = MarketingMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('artwork')
                    ->collection('artwork')
                    ->conversion('thumb')
                    ->label('')
                    ->size(56),

                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('description')->limit(50)->placeholder('—')->toggleable(),
                TextColumn::make('caption_template')->label('Caption')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('sort_order')->label('Order')->alignCenter()->sortable()->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
