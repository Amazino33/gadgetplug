<?php

namespace App\Filament\Resources\Affiliates\Tables;

use App\Models\Affiliate;
use App\Services\Affiliate\WalletService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AffiliatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->weight('bold')->copyable(),
                TextColumn::make('user.name')->label('Name')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable(),

                TextColumn::make('level.name')
                    ->label('Level')
                    ->badge()
                    ->color('success')
                    ->placeholder('—'),

                TextColumn::make('pending_balance')
                    ->label('Pending')
                    ->getStateUsing(fn (Affiliate $record) => '₦' . number_format(
                        app(WalletService::class)->pendingBalance($record->id), 2
                    )),

                TextColumn::make('available_balance')
                    ->label('Available')
                    ->weight('bold')
                    ->getStateUsing(fn (Affiliate $record) => '₦' . number_format(
                        app(WalletService::class)->availableBalance($record->id), 2
                    )),

                TextColumn::make('commissions_count')
                    ->label('Orders')
                    ->counts('commissions')
                    ->alignCenter(),

                IconColumn::make('is_active')->label('Active')->boolean(),

                TextColumn::make('created_at')->label('Joined')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
