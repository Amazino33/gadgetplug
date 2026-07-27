<?php

namespace App\Filament\Vendor\Resources\Procurements;

use App\Models\Procurement;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static string|null|\UnitEnum  $navigationGroup = 'Procurement';
    protected static ?string                $navigationLabel = 'Procurements';
    protected static ?int                   $navigationSort  = 11;

    public static function canAccess(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('procurements')) {
            return false;
        }

        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
    }

    // Storekeepers can only create; owners/managers can also view all statuses
    public static function canCreate(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'create_products');
    }

    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        // Not used directly — wizard is on the create page
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
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

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),

                TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Amount Paid')
                    ->money('NGN'),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'full'         => 'success',
                        'part_payment' => 'warning',
                        'credit'       => 'danger',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'full'         => 'Fully Paid',
                        'part_payment' => 'Part-Payment',
                        'credit'       => 'Credit',
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'voided'   => 'danger',
                    }),

                TextColumn::make('creator.name')
                    ->label('Logged By')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'voided' => 'Voided']),
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(['full' => 'Fully Paid', 'part_payment' => 'Part-Payment', 'credit' => 'Credit']),
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
            'index'  => Pages\ListProcurements::route('/'),
            'create' => Pages\CreateProcurement::route('/create'),
            'view'   => Pages\ViewProcurement::route('/{record}'),
        ];
    }
}
