<?php

namespace App\Filament\Vendor\Resources\Orders\Tables;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Order Ref')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn(Order $record): string => $record->customer_phone),

                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack')
                    ->color(fn($state) => $state === 'pay_on_delivery' ? 'warning' : 'success'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state) => match($state) {
                        'pending'               => 'Pending',
                        'confirmed'             => 'Confirmed',
                        'paid'                  => 'Paid',
                        'shipped'               => 'Dispatched',
                        'delivered'             => 'Delivered',
                        'cancelled'             => 'Cancelled',
                        'paid_but_failed_stock' => 'Stock Issue',
                        default                 => ucfirst($state),
                    })
                    ->color(fn($state) => match($state) {
                        'pending'               => 'gray',
                        'confirmed'             => 'info',
                        'paid'                  => 'success',
                        'shipped'               => 'warning',
                        'delivered'             => 'success',
                        'cancelled'             => 'danger',
                        'paid_but_failed_stock' => 'danger',
                        default                 => 'gray',
                    }),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('NGN')
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'confirmed' => 'Confirmed',
                        'paid'      => 'Paid',
                        'shipped'   => 'Dispatched',
                        'delivered' => 'Delivered',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('payment_method')
                    ->label('Payment')
                    ->options([
                        'pay_on_delivery' => 'Pay on Delivery',
                        'paystack'        => 'Paystack',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-oval-left')
                    ->color('success')
                    ->url(fn (Order $record) => 'https://wa.me/' . preg_replace('/\D/', '', $record->customer_phone))
                    ->openUrlInNewTab(),
                Action::make('updateStatus')
                    ->label('Update Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->schema([
                        Select::make('status')
                            ->label('New Status')
                            ->options(fn(Order $record) => match($record->status) {
                                'pending', 'confirmed' => [
                                    'shipped'   => 'Mark as Dispatched',
                                    'cancelled' => 'Cancel Order',
                                ],
                                'paid' => [
                                    'shipped'   => 'Mark as Dispatched',
                                    'cancelled' => 'Cancel Order',
                                ],
                                'shipped' => [
                                    'delivered' => 'Mark as Delivered',
                                    'cancelled' => 'Cancel Order',
                                ],
                                default => [],
                            })
                            ->required(),
                    ])
                    ->action(fn(Order $record, array $data) => $record->update(['status' => $data['status']]))
                    ->visible(fn(Order $record) => !in_array($record->status, ['delivered', 'cancelled', 'paid_but_failed_stock'])),
            ]);
    }
}
