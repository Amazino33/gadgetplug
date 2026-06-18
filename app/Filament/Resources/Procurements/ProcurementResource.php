<?php

namespace App\Filament\Resources\Procurements;

use App\Models\Procurement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Procurements';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($r): bool   { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),
                TextColumn::make('vendor.name')->label('Store')->searchable()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('items_count')->label('Items')->counts('items')->alignCenter(),
                TextColumn::make('total_cost')->money('NGN')->sortable(),
                TextColumn::make('amount_paid')->money('NGN'),

                TextColumn::make('payment_status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'full'         => 'success',
                        'part_payment' => 'warning',
                        'credit'       => 'danger',
                        default        => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'full'         => 'Fully Paid',
                        'part_payment' => 'Part-Payment',
                        'credit'       => 'Credit',
                        default        => $state,
                    }),

                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'voided'   => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('variance_flag')->label('⚠ Variance')
                    ->getStateUsing(function (Procurement $record): string {
                        return $record->items()->with('product')->get()->some(
                            fn ($i) => $i->hasCostVariance()
                        ) ? '⚠ Yes' : '—';
                    })
                    ->color(fn (string $state) => $state !== '—' ? 'warning' : 'gray')
                    ->alignCenter(),

                TextColumn::make('creator.name')->label('Logged By')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'voided' => 'Voided']),
                SelectFilter::make('vendor_id')->label('Store')->relationship('vendor', 'name'),
            ])
            ->recordAction('view')
            ->actions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Procurement $record) => Pages\ViewProcurement::getUrl(['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcurements::route('/'),
            'view'  => Pages\ViewProcurement::route('/{record}'),
        ];
    }
}
