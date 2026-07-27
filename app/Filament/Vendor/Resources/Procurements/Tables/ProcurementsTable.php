<?php

namespace App\Filament\Vendor\Resources\Procurements\Tables;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProcurementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref #')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending', 'draft' => 'gray',
                        'awaiting_logistics' => 'info',
                        'approved', 'reconciled' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'awaiting_logistics' => 'Awaiting Logistics',
                        default => ucfirst($state),
                    }),

                TextColumn::make('items_count')
                    ->label('Lines')
                    ->counts('items')
                    ->alignCenter(),

                TextColumn::make('trip_value')
                    ->label('Trip Value')
                    ->getStateUsing(fn (Procurement $record) => $record->items()
                        ->selectRaw('SUM(quantity * unit_cost) as total')
                        ->value('total') ?? 0)
                    ->money('NGN'),

                TextColumn::make('logistics_cost')
                    ->label('Logistics Cost')
                    ->money('NGN')
                    ->placeholder('—'),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('NGN')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'full' => 'success',
                        'part_payment' => 'warning',
                        'credit' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'full' => 'Fully Paid',
                        'part_payment' => 'Part-Payment',
                        'credit' => 'Credit',
                        default => ucfirst($state),
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('logisticsRecorder.name')
                    ->label('Logistics By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reconciled_at')
                    ->label('Reconciled')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'awaiting_logistics' => 'Awaiting Logistics',
                        'reconciled' => 'Reconciled',
                        'pending' => 'Pending (legacy)',
                        'approved' => 'Approved (legacy)',
                        'voided' => 'Voided',
                    ]),
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options([
                        'full' => 'Fully Paid',
                        'part_payment' => 'Part-Payment',
                        'credit' => 'Credit',
                    ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordAction('view')
            ->actions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Procurement $record) => ProcurementResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
