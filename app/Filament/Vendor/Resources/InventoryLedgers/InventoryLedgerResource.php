<?php

namespace App\Filament\Vendor\Resources\InventoryLedgers;

use App\Models\InventoryLedger;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class InventoryLedgerResource extends Resource
{
    protected static ?string $model = InventoryLedger::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-arrows-up-down';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Stock Movement';
    protected static ?int                   $navigationSort  = 5;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    private const TYPE_LABELS = [
        'online_sale'           => 'Online Sale',
        'pos_sale'               => 'POS Sale',
        'pos_void'               => 'POS Sale Voided',
        'pos_return'             => 'POS Return',
        'restock'                => 'Restock',
        'audit_correction'       => 'Audit Correction',
        'refund'                 => 'Refund',
        'reserved'               => 'Reserved',
        'dispatched'             => 'Dispatched',
        'reservation_released'   => 'Reservation Released',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->weight('bold')
                    ->placeholder('— (product deleted)'),

                TextColumn::make('transaction_type')
                    ->label('Movement')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::TYPE_LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn ($state) => match ($state) {
                        'restock', 'reservation_released' => 'success',
                        'pos_sale', 'online_sale', 'dispatched' => 'warning',
                        'pos_void', 'pos_return', 'refund'      => 'info',
                        'audit_correction'                       => 'gray',
                        default                                  => 'gray',
                    }),

                TextColumn::make('quantity_change')
                    ->label('Qty Change')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->weight('bold')
                    ->alignCenter(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->label('Notes')
                    ->wrap()
                    ->placeholder('—')
                    ->limit(60),

                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('transaction_type')
                    ->label('Movement')
                    ->options(self::TYPE_LABELS)
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryLedgers::route('/'),
        ];
    }
}
