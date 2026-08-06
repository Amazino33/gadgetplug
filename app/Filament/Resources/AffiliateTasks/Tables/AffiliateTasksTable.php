<?php

namespace App\Filament\Resources\AffiliateTasks\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AffiliateTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),

                TextColumn::make('verification_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'auto' ? 'Auto' : 'Manual')
                    ->color(fn ($state) => $state === 'auto' ? 'info' : 'warning'),

                TextColumn::make('reward_amount')->label('Reward')->money('NGN'),

                IconColumn::make('counts_toward_level')->label('Level Progress')->boolean(),

                TextColumn::make('max_completions_per_affiliate')->label('Max Completions')->placeholder('Unlimited'),

                TextColumn::make('cooldown_days')->label('Cooldown')->placeholder('None')->formatStateUsing(fn ($state) => $state ? "{$state}d" : null),

                TextColumn::make('submissions_count')->label('Submissions')->counts('submissions')->alignCenter(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('verification_type')->options(['auto' => 'Auto', 'manual' => 'Manual']),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
