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

                // The customer's own answer to "when do you want it", captured on
                // the checkout success screen. Colour-coded because this column
                // exists to be scanned for urgency, not read line by line.
                // isToday() precedes isPast(): a date cast lands at midnight, so
                // today is already "past" by mid-morning and would otherwise
                // never reach its own branch.
                TextColumn::make('preferred_delivery_date')
                    ->label('Wanted')
                    ->badge()
                    ->sortable()
                    ->placeholder('Not set')
                    ->formatStateUsing(fn ($state, Order $record) => $record->deliveryPreferenceLabel() ?? '—')
                    ->color(fn ($state, Order $record) => match (true) {
                        $record->preferred_delivery_date === null     => 'gray',
                        $record->preferred_delivery_date->isToday()   => 'danger',
                        $record->preferred_delivery_date->isPast()    => 'danger',
                        $record->preferred_delivery_date->isTomorrow()=> 'warning',
                        default                                       => 'success',
                    }),

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
                            ->live()
                            ->required(),

                        // Without this, marking a pay-on-delivery order delivered
                        // from the list left the observer with no channel to post
                        // to, so the sale showed on the Sales Report and never
                        // reached the Financial Report. Asked here for the same
                        // reason the order page asks it.
                        Select::make('payment_channel')
                            ->label('How did the customer pay on delivery?')
                            ->options(['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer'])
                            ->required()
                            ->helperText('The full order amount is added to whichever account you pick, as money received — once, right now.')
                            ->visible(fn(Order $record, $get) => $get('status') === 'delivered'
                                && $record->payment_method === 'pay_on_delivery'
                                && ! $record->isRevenueRecognized()),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $update = ['status' => $data['status']];

                        if (filled($data['payment_channel'] ?? null)) {
                            $update['payment_channel'] = $data['payment_channel'];
                        }

                        $record->update($update);
                    })
                    ->visible(fn(Order $record) => !in_array($record->status, ['delivered', 'cancelled', 'paid_but_failed_stock'])),
            ]);
    }
}
