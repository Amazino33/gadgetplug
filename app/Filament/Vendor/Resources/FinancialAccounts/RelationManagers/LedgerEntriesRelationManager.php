<?php

namespace App\Filament\Vendor\Resources\FinancialAccounts\RelationManagers;

use App\Models\FinancialLedgerEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Read-only by design — every row here is append-only at the model level
// (FinancialLedgerEntry blocks update/delete), so there is nothing for this
// table to let anyone edit even if an action were added.
class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'in' ? 'In' : 'Out')
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'danger'),

                TextColumn::make('amount')
                    ->money('NGN')
                    ->weight('bold'),

                TextColumn::make('source_type')
                    ->label('Source')
                    ->placeholder('Manual')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : null),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—'),

                TextColumn::make('creator.name')
                    ->label('Recorded By')
                    ->placeholder('System'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('occurred_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('occurred_at', '<=', $date));
                    }),

                SelectFilter::make('source_type')
                    ->label('Source')
                    ->options(fn (): array => FinancialLedgerEntry::query()
                        ->whereNotNull('source_type')
                        ->distinct()
                        ->pluck('source_type', 'source_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                        ->all()
                    ),
            ]);
    }
}
