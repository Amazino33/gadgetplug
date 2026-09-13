<?php

namespace App\Filament\Resources\HelpCategories\Pages;

use App\Filament\Resources\HelpCategories\HelpCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ManageHelpCategories extends ManageRecords
{
    protected static string $resource = HelpCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('slug')->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('articles_count')
                    ->label('Articles')
                    ->counts('articles')
                    ->alignCenter(),
                TextColumn::make('sort_order')->label('Order')->alignCenter()->sortable(),
                IconColumn::make('is_published')->label('Visible')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
