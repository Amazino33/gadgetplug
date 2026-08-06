<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon  = Heroicon::OutlinedShoppingBag;
    protected static ?string                $navigationLabel = 'Orders';
    protected static ?int                   $navigationSort  = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($r): bool   { return false; }
    public static function canDelete($r): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['items.product', 'items.vendor', 'user'])
            ->latest();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),

                TextColumn::make('vendor_name')
                    ->label('Store')
                    ->getStateUsing(fn (Order $record) => $record->items->first()?->vendor?->name ?? '—'),

                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('customer_phone')->label('Phone'),

                TextColumn::make('total_amount')->label('Total')->money('NGN')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'               => 'Pending',
                        'confirmed'             => 'Confirmed',
                        'paid'                  => 'Paid',
                        'shipped'               => 'Dispatched',
                        'delivered'             => 'Delivered',
                        'cancelled'             => 'Cancelled',
                        'paid_but_failed_stock' => 'Stock Issue',
                        default                 => ucfirst($state),
                    })
                    ->color(fn ($state) => match ($state) {
                        'confirmed'                          => 'info',
                        'paid', 'delivered'                  => 'success',
                        'shipped'                            => 'warning',
                        'cancelled', 'paid_but_failed_stock' => 'danger',
                        default                              => 'gray',
                    }),

                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pay_on_delivery' => 'Pay on Delivery',
                        'wallet'           => 'Reseller (Wallet)',
                        default            => 'Paystack',
                    })
                    ->color(fn ($state) => match ($state) {
                        'wallet' => 'info',
                        default  => 'gray',
                    }),

                TextColumn::make('created_at')->label('Placed')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'               => 'Pending',
                        'confirmed'             => 'Confirmed',
                        'paid'                  => 'Paid',
                        'shipped'               => 'Dispatched',
                        'delivered'             => 'Delivered',
                        'cancelled'             => 'Cancelled',
                        'paid_but_failed_stock' => 'Stock Issue',
                    ]),

                SelectFilter::make('vendor_id')
                    ->label('Store')
                    ->options(fn () => Vendor::pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $vendorId) => $q->whereHas('items', fn (Builder $q2) => $q2->where('vendor_id', $vendorId))
                    )),

                SelectFilter::make('payment_method')
                    ->label('Payment')
                    ->options([
                        'paystack'        => 'Paystack',
                        'pay_on_delivery' => 'Pay on Delivery',
                        'wallet'          => 'Reseller (Wallet)',
                    ]),
            ])
            ->recordAction('view')
            ->actions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $record) => Pages\ViewOrder::getUrl(['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view'  => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
