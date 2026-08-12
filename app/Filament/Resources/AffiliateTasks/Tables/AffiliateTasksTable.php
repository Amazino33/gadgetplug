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

                TextColumn::make('task_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'auto'               => 'Auto',
                        'daily_social_share' => 'Daily Share',
                        default              => 'Manual',
                    })
                    ->color(fn ($state) => match ($state) {
                        'auto'               => 'info',
                        'daily_social_share' => 'success',
                        default              => 'warning',
                    }),

                // Rewards are Plug Points, not cash. A daily share has no flat
                // figure — it pays whatever band the reported reach lands in.
                TextColumn::make('points_reward')
                    ->label('Reward')
                    ->formatStateUsing(fn ($state, $record) => $record->task_type === 'daily_social_share'
                        ? 'By reach band'
                        : number_format((int) $state) . ' pts'),

                IconColumn::make('counts_toward_level')->label('Level Progress')->boolean(),

                TextColumn::make('max_completions_per_affiliate')->label('Max Completions')->placeholder('Unlimited'),

                TextColumn::make('cooldown_days')->label('Cooldown')->placeholder('None')->formatStateUsing(fn ($state) => $state ? "{$state}d" : null),

                TextColumn::make('submissions_count')->label('Submissions')->counts('submissions')->alignCenter(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('task_type')->label('Type')->options([
                    'auto'               => 'Auto',
                    'manual'             => 'Manual',
                    'daily_social_share' => 'Daily Share',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
