<?php

namespace App\Filament\Vendor\Resources\Orders\Tables;

use App\Models\Order;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

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
                    ->formatStateUsing(fn($state) => OrderStatusService::LABELS[$state] ?? ucfirst($state))
                    ->color(fn($state) => match($state) {
                        'pending'               => 'gray',
                        'confirmed'             => 'info',
                        'paid'                  => 'success',
                        'packed'                => 'info',
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
                    ->options(OrderStatusService::LABELS),
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
                        // The store's steps only — see OrderStatusService.
                        Select::make('status')
                            ->label('New Status')
                            ->options(fn(Order $record) => OrderStatusService::storeOptionsFor($record))
                            ->live()
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data): void {
                        try {
                            OrderStatusService::applyStoreStep($record, auth()->user(), $data['status']);
                        } catch (InvalidOrderTransition|AuthorizationException $e) {
                            Notification::make()->title($e instanceof AuthorizationException ? 'Only staff of the store holding this order can update it.' : $e->getMessage())->danger()->send();
                        }
                    })
                    ->visible(fn(Order $record) => OrderStatusService::storeOptionsFor($record) !== []
                        && auth()->user()->can('fulfil', $record)),
            ]);
    }
}
