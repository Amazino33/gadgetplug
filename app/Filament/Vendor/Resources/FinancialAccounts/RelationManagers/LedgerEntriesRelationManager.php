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

    // Plain-English names for what's shown in the "Source" column — a raw
    // class_basename() (e.g. "ProcurementLogisticsLeg") means nothing to a
    // vendor reading their own money history.
    private const SOURCE_LABELS = [
        'Order'                   => 'Order',
        'Expense'                 => 'Expense',
        'ProcurementLogisticsLeg' => 'Logistics (Procurement)',
    ];

    private static function sourceLabel(?string $morphClass): ?string
    {
        if (! $morphClass) {
            return null;
        }

        $basename = class_basename($morphClass);

        return self::SOURCE_LABELS[$basename] ?? $basename;
    }

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
                    ->label('What this was for')
                    ->placeholder('Manual entry')
                    ->formatStateUsing(fn (?string $state) => self::sourceLabel($state)),

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
                    ->label('Date range')
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
                    ->label('What it was for')
                    ->options(fn (): array => FinancialLedgerEntry::query()
                        ->whereNotNull('source_type')
                        ->distinct()
                        ->pluck('source_type', 'source_type')
                        ->mapWithKeys(fn ($type) => [$type => self::sourceLabel($type)])
                        ->all()
                    ),
            ]);
    }
}
