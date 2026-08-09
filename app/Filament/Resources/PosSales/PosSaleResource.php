<?php

namespace App\Filament\Resources\PosSales;

use App\Models\PosSale;
use App\Models\Vendor;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

// Read-only, cross-vendor oversight — mirrors the admin Orders resource, but
// for POS sales, which previously had no admin-side visibility at all.
class PosSaleResource extends Resource
{
    protected static ?string $model = PosSale::class;

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-receipt-percent';
    protected static ?string                $navigationLabel = 'POS Sales';
    protected static ?int                   $navigationSort  = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['vendor', 'cashier', 'items'])
            ->latest('completed_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),

                TextColumn::make('vendor.name')
                    ->label('Store')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->placeholder('—'),

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
                        'completed'                  => 'success',
                        'voided'                     => 'danger',
                        'refunded', 'partial_refund' => 'warning',
                        default                      => 'gray',
                    }),

                TextColumn::make('total')->label('Total')->money('NGN')->sortable(),

                TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->defaultSort('completed_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'completed'      => 'Completed',
                        'voided'         => 'Voided',
                        'refunded'       => 'Refunded',
                        'partial_refund' => 'Partial Refund',
                    ]),
                SelectFilter::make('vendor_id')
                    ->label('Store')
                    ->options(fn () => Vendor::pluck('name', 'id'))
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
