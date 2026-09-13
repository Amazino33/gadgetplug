<?php

namespace App\Filament\Resources\SystemAnnouncements\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SystemAnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('target_group')
                    ->badge()
                    ->color('info')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('users_count')
                    ->label('Reads (Auth)')
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('guest_views')
                    ->label('Reads (Guest)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('action_taken_count')
                    ->label('Clicks (Auth)')
                    ->getStateUsing(fn ($record) => $record->users()->whereNotNull('action_taken_at')->count()),
                TextColumn::make('guest_clicks')
                    ->label('Clicks (Guest)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
