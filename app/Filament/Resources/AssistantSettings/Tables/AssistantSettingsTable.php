<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantSettings\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AssistantSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Vendor User')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('user.ownedVendors.name')
                    ->label('Store / Shop')
                    ->badge()
                    ->color('warning')
                    ->searchable(),

                ToggleColumn::make('admin_disabled')
                    ->label('Admin Blocked')
                    ->sortable(),

                IconColumn::make('vendor_disabled')
                    ->label('Vendor Disabled')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('activeStore.name')
                    ->label('Active Branch')
                    ->placeholder('None')
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc');
    }
}
