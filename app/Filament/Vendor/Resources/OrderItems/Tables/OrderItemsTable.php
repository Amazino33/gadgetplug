<?php

namespace App\Filament\Vendor\Resources\OrderItems\Tables;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.reference')
                    ->label('Order Ref')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->order?->local_government),

                TextColumn::make('order.status')
                    ->label('Order Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'               => 'Pending',
                        'confirmed'             => 'Confirmed',
                        'paid'                  => 'Paid',
                        'shipped'               => 'Dispatched',
                        'delivered'             => 'Delivered',
                        'cancelled'             => 'Cancelled',
                        'paid_but_failed_stock' => 'Stock Issue',
                        default                 => ucfirst((string) $state),
                    })
                    ->color(fn ($state) => match ($state) {
                        'confirmed'                          => 'info',
                        'paid', 'delivered'                  => 'success',
                        'shipped'                            => 'warning',
                        'cancelled', 'paid_but_failed_stock' => 'danger',
                        default                              => 'gray',
                    }),

                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('order.customer_name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('order.customer_phone')
                    ->label('Phone')
                    ->searchable(),

                TextColumn::make('order.shipping_address')
                    ->label('Address')
                    ->wrap(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('order_status')
                    ->label('Order Status')
                    ->options([
                        'pending'               => 'Pending',
                        'confirmed'             => 'Confirmed',
                        'paid'                  => 'Paid',
                        'shipped'               => 'Dispatched',
                        'delivered'             => 'Delivered',
                        'cancelled'             => 'Cancelled',
                        'paid_but_failed_stock' => 'Stock Issue',
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $status) => $q->whereHas('order', fn ($q2) => $q2->where('status', $status))
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                // Goes to the order page's Send Message action instead of a blank
                // WhatsApp chat, so the vendor's templates are used and the
                // message is logged against the order.
                Action::make('messageCustomer')
                    ->label('Message')
                    ->icon('heroicon-o-chat-bubble-oval-left')
                    ->color('success')
                    ->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record->order_id], tenant: filament()->getTenant())),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
