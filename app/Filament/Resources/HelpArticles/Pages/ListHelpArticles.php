<?php

namespace App\Filament\Resources\HelpArticles\Pages;

use App\Filament\Resources\HelpArticles\HelpArticleResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ListHelpArticles extends ListRecords
{
    protected static string $resource = HelpArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New article')];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->weight('bold')->searchable()->wrap(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                TextColumn::make('tour_key')
                    ->label('Tour')
                    ->badge()
                    ->color('info')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('sort_order')->label('Order')->alignCenter()->sortable(),

                IconColumn::make('is_published')->label('Published')->boolean(),

                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            // Category then position, so the table reads in the same order the
            // vendor sees down the page. Sorting by "last updated" would put the
            // list in an order nobody is trying to reproduce.
            ->defaultSort('sort_order')
            ->defaultGroup('category.name')
            ->filters([
                SelectFilter::make('help_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->preload(),

                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
