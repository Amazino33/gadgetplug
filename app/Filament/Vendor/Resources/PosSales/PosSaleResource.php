<?php

namespace App\Filament\Vendor\Resources\PosSales;

use App\Models\PosSale;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
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
            ->with(['cashier', 'items'])
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

                TextColumn::make('total')->label('Total')->money('NGN')->sortable(),

                TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->defaultSort('completed_at', 'desc')
            ->actions([
                \Filament\Tables\Actions\Action::make('void')
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
                    ->visible(fn (PosSale $record) => $record->status === 'completed' && (
                        auth()->user()->isSuperAdmin() ||
                        filament()->getTenant()?->isOwner(auth()->user()) ||
                        auth()->user()->hasVendorPermission(filament()->getTenant()?->id, 'void_sale')
                    ))
                    ->action(function (PosSale $record, array $data, \App\Actions\Inventory\AdjustStockAction $adjustStock, \App\Actions\Finance\RecognizePosSaleRevenueAction $revenue) {
                        $user = auth()->user();
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data, $adjustStock, $revenue, $user) {
                            foreach ($record->items as $item) {
                                $adjustStock->execute(
                                    productId: $item->product_id,
                                    quantityChanged: $item->quantity,
                                    transactionType: 'pos_void',
                                    userId: $user->id,
                                    reference: $record->reference,
                                    description: "Void POS sale — {$item->product_name}. Reason: {$data['reason']}"
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosSales::route('/'),
        ];
    }
}
