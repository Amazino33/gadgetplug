<?php

namespace App\Filament\Vendor\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Order Details')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),

                    TextEntry::make('status')
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

                    TextEntry::make('payment_method')
                        ->label('Payment')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack')
                        ->color(fn ($state) => $state === 'pay_on_delivery' ? 'warning' : 'success'),

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
                    TextEntry::make('customer_name')
                        ->label('Name')
                        ->weight('bold'),

                    TextEntry::make('customer_phone')
                        ->label('Phone')
                        ->url(fn ($state) => 'tel:' . preg_replace('/\D/', '', $state))
                        ->color('info')
                        ->copyable(),

                    TextEntry::make('customer_email')
                        ->label('Email')
                        ->columnSpanFull(),

                    TextEntry::make('shipping_address')
                        ->label('Delivery Address')
                        ->columnSpanFull()
                        ->copyable(),
                ]),

            Section::make('Items Ordered')
                ->schema([
                    RepeatableEntry::make('items')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('product.name')
                                ->label('Product')
                                ->weight('bold')
                                ->columnSpan(2),

                            TextEntry::make('quantity'),

                            TextEntry::make('unit_price')
                                ->label('Unit Price')
                                ->money('NGN'),

                            TextEntry::make('line_total')
                                ->label('Line Total')
                                ->money('NGN')
                                ->getStateUsing(fn ($record) => $record->quantity * $record->unit_price),
                        ]),
                ]),

        ]);
    }
}
