<?php

namespace App\Filament\Resources\ClientRequests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;

class ClientRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                
                TextColumn::make('request_text')
                    ->label('The Task')
                    ->wrap()
                    ->searchable(),
                
                ToggleColumn::make('is_completed')
                    ->label('Done?')
                    ->sortable(),
                
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable()
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_completed')
                    ->label('Task Status')
                    ->placeholder('All Tasks')
                    ->trueLabel('Completed')
                    ->falseLabel('Pending'),
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
