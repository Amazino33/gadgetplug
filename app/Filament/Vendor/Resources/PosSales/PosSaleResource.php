<?php

namespace App\Filament\Vendor\Resources\PosSales;

use App\Models\PosSale;
use App\Services\ActiveStore;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

// Read-only — the actual voiding/return workflow lives in the POS app itself,
// where stock and payment reconciliation happen together. This is for the
// owner/manager to see every sale across every till and cashier, not just
// the aggregated totals Sales Report shows.
class PosSaleResource extends Resource
{
    protected static ?string $model = PosSale::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-receipt-percent';
    protected static string|null|UnitEnum   $navigationGroup = 'Point of Sale';
    protected static ?string                $navigationLabel = 'POS Sales';
    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_inventory_reports')
        );
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['cashier', 'items', 'store'])
            ->latest('completed_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),

                TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->placeholder('—'),

                // Only worth a column when there is more than one branch to
                // tell apart. A single-store vendor sees the table unchanged.
                TextColumn::make('store.name')
                    ->label('Store')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->visible(fn (): bool => count(self::storeOptions()) > 1)
                    ->sortable(),

                TextColumn::make('item_summary')
                    ->label('Items')
                    ->getStateUsing(fn (PosSale $record): string => $record->items
                        ->pluck('product_name')
                        ->implode(', ')
                    )
                    ->wrap()
                    ->limit(60),

                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'cash'          => 'Cash',
                        'card'          => 'Card',
                        'bank_transfer' => 'Bank Transfer',
                        'split'         => 'Split',
                        default         => ucfirst($state),
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'completed'      => 'Completed',
                        'voided'         => 'Voided',
                        'refunded'       => 'Refunded',
                        'partial_refund' => 'Partial Refund',
                        default          => ucfirst($state),
                    })
                    ->color(fn ($state) => match ($state) {
                        'completed'                       => 'success',
                        'voided'                          => 'danger',
                        'refunded', 'partial_refund'      => 'warning',
                        default                           => 'gray',
                    }),

                // Summed over exactly the rows on screen, so the figure always
                // agrees with the tab and filters in force. Note this is the
                // gross total taken at the till, VAT included — Sales Report
                // reports revenue net of VAT, so the two answer different
                // questions and will not match.
                TextColumn::make('total')
                    ->label('Total')
                    ->money('NGN')
                    ->sortable()
                    ->summarize(Sum::make()->label('Takings')->money('NGN')),

                TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->defaultSort('completed_at', 'desc')
            ->recordActions([
                Action::make('void')
                    ->label('Void Sale')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalHeading('Void POS Sale')
                    ->modalDescription('Are you sure you want to void this sale? The stock will be returned to inventory and revenue will be reversed. This cannot be undone.')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('reason')
                            ->label('Reason for voiding')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Duplicate sale, customer returned immediately...'),
                    ])
                    ->visible(fn (?PosSale $record) => $record === null || (auth()->check() && $record->status === 'completed' && (
                        auth()->user()->isSuperAdmin() ||
                        filament()->getTenant()?->isOwner(auth()->user()) ||
                        auth()->user()->hasVendorPermission($record->vendor_id, 'void_sale')
                    )))
                    ->action(function (PosSale $record, array $data) {
                        $user = auth()->user();
                        $adjustStock = app(\App\Actions\Inventory\AdjustStockAction::class);
                        $revenue = app(\App\Actions\Finance\RecognizePosSaleRevenueAction::class);
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data, $adjustStock, $revenue, $user) {
                            // Ordered by product id, as the sale is: these row
                            // locks can otherwise deadlock against a concurrent
                            // till, and voiding a run of duplicates is exactly
                            // when several of these run together.
                            foreach ($record->items->sortBy('product_id') as $item) {
                                $adjustStock->execute(
                                    productId: $item->product_id,
                                    quantityChanged: $item->quantity,
                                    transactionType: 'pos_void',
                                    userId: $user->id,
                                    reference: $record->reference,
                                    description: "Void POS sale — {$item->product_name}. Reason: {$data['reason']}",
                                    // Back to the branch it was sold from, not
                                    // the vendor's default store.
                                    store: $record->store_id,
                                );
                            }

                            $record->update(['status' => 'voided']);

                            $revenue->reverseForVoid($record);

                            activity()->causedBy($user)
                                ->performedOn($record)
                                ->tap(fn ($a) => $a->vendor_id = $record->vendor_id)
                                ->log("Voided sale {$record->reference}. Reason: {$data['reason']}");

                            if ($record->customer_id) {
                                \App\Models\PosCustomer::where('id', $record->customer_id)->decrement('total_spent', $record->total);
                                \App\Models\PosCustomer::where('id', $record->customer_id)->decrement('total_transactions');
                            }
                        });
                    })
            ])
            ->filters([
                // Daily is the question this page is actually asked, so both
                // ends default to today and the owner opens on the day's
                // trade rather than on every sale ever rung.
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('From')->default(today())->maxDate(now()),
                        DatePicker::make('until')->label('Until')->default(today())->maxDate(now()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        // COALESCE because an offline sale replayed later has a
                        // completed_at from the till, while anything that never
                        // got one still has to appear on the day it was made.
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, $date) => $q->whereRaw(
                                'COALESCE(pos_sales.completed_at, pos_sales.created_at) >= ?',
                                [\Illuminate\Support\Carbon::parse($date)->startOfDay()],
                            ),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, $date) => $q->whereRaw(
                                'COALESCE(pos_sales.completed_at, pos_sales.created_at) <= ?',
                                [\Illuminate\Support\Carbon::parse($date)->endOfDay()],
                            ),
                        ))
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (! $from && ! $until) {
                            return null;
                        }

                        if ($from && $until && $from === $until) {
                            return \Illuminate\Support\Carbon::parse($from)->isToday()
                                ? 'Today'
                                : \Illuminate\Support\Carbon::parse($from)->format('d M Y');
                        }

                        return trim(($from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : '…')
                            .' — '
                            .($until ? \Illuminate\Support\Carbon::parse($until)->format('d M Y') : '…'));
                    }),

                SelectFilter::make('status')
                    ->options([
                        'completed'      => 'Completed',
                        'voided'         => 'Voided',
                        'refunded'       => 'Refunded',
                        'partial_refund' => 'Partial Refund',
                    ]),
                SelectFilter::make('payment_method')
                    ->label('Payment')
                    ->options([
                        'cash'          => 'Cash',
                        'card'          => 'Card',
                        'bank_transfer' => 'Bank Transfer',
                        'split'         => 'Split',
                    ]),
                SelectFilter::make('cashier_id')
                    ->label('Cashier')
                    ->relationship('cashier', 'name')
                    ->searchable(),
            ]);
    }

    /**
     * The branches this user may see, reused from the same helper the Sales
     * Report uses so the two screens can never offer different lists.
     */
    public static function storeOptions(): array
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (! $vendor || ! $user) {
            return [];
        }

        return ActiveStore::accessibleFor($vendor, $user)->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosSales::route('/'),
        ];
    }
}
