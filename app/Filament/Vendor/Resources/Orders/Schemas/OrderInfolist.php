<?php

namespace App\Filament\Vendor\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order Details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')
                            ->copyable()
                            ->weight('bold'),

                        TextEntry::make('status')
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
                                'confirmed'             => 'info',
                                'paid'                  => 'success',
                                'shipped'               => 'warning',
                                'delivered'             => 'success',
                                'cancelled', 'paid_but_failed_stock' => 'danger',
                                default                 => 'gray',
                            }),

                        TextEntry::make('payment_method')
                            ->label('Payment')
                            ->badge()
                            ->formatStateUsing(fn($state) => $state === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack')
                            ->color(fn($state) => $state === 'pay_on_delivery' ? 'warning' : 'success'),

                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->money('NGN')
                            ->weight('bold'),

                        TextEntry::make('created_at')
                            ->label('Placed at')
                            ->dateTime('d M Y, g:ia'),
                    ]),

                Section::make('Customer')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('customer_name')->label('Name'),
                        TextEntry::make('customer_phone')->label('Phone'),
                        TextEntry::make('customer_email')->label('Email')->columnSpanFull(),
                        TextEntry::make('shipping_address')->label('Delivery Address')->columnSpanFull(),
                    ]),

                Section::make('Items Ordered')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('product.name')->label('Product'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_price')->label('Unit Price')->money('NGN'),
                            ]),
                    ]),
            ]);
    }
}
