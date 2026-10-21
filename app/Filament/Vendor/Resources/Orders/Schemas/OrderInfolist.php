<?php

namespace App\Filament\Vendor\Resources\Orders\Schemas;

use App\Services\Orders\OrderStatusService;
use App\Models\Affiliate;
use App\Models\Order;
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
                        ->formatStateUsing(fn ($state) => OrderStatusService::LABELS[$state] ?? ucfirst((string) $state))
                        ->color(fn ($state) => match ($state) {
                            'confirmed', 'packed'                => 'info',
                            'paid', 'delivered'                  => 'success',
                            'shipped'                            => 'warning',
                            'cancelled', 'paid_but_failed_stock' => 'danger',
                            default                              => 'gray',
                        }),

                    TextEntry::make('payment_method')
                        ->label('Payment')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'pay_on_delivery' => 'Pay on Delivery',
                            'wallet'           => 'Reseller (Wallet)',
                            default            => 'Paystack',
                        })
                        ->color(fn ($state) => match ($state) {
                            'pay_on_delivery' => 'warning',
                            'wallet'           => 'info',
                            default            => 'success',
                        }),

                    TextEntry::make('total_amount')
                        ->label('Total')
                        ->money('NGN')
                        ->weight('bold'),

                    TextEntry::make('created_at')
                        ->label('Placed at')
                        ->dateTime('d M Y, g:ia'),
                ]),

            Section::make('Reseller Purchase')
                ->columns(3)
                ->visible(fn (Order $record) => $record->payment_method === 'wallet')
                ->schema([
                    TextEntry::make('reseller_affiliate_code')
                        ->label('Affiliate')
                        ->getStateUsing(fn (Order $record) => Affiliate::where('user_id', $record->user_id)->first()?->code ?? '—'),

                    TextEntry::make('reseller_affiliate_name')
                        ->label('Name')
                        ->getStateUsing(fn (Order $record) => $record->user?->name ?? '—'),

                    TextEntry::make('walletDebit.amount')
                        ->label('Wallet Debited')
                        ->weight('bold')
                        ->color('danger')
                        ->formatStateUsing(fn ($state) => $state !== null ? '₦' . number_format(abs((float) $state), 2) : '—'),
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

                    TextEntry::make('local_government')
                        ->label('Local Government Area')
                        ->placeholder('—'),

                    TextEntry::make('shipping_address')
                        ->label('Delivery Address')
                        ->columnSpanFull()
                        ->copyable(),
                ]),

            Section::make('Logistics')
                ->columns(2)
                ->schema([
                    TextEntry::make('logisticsCompany.name')
                        ->label('Company')
                        ->placeholder('Not assigned yet')
                        ->weight('bold'),

                    // GadgetPlug's dispatch names the rider on the order; a
                    // vendor's own rider record only on orders from before that.
                    TextEntry::make('rider')
                        ->label('Rider')
                        ->getStateUsing(fn (Order $record) => $record->rider_name
                            ? trim($record->rider_name.' '.($record->rider_phone ? '('.$record->rider_phone.')' : ''))
                            : $record->deliveryPerson?->name)
                        ->placeholder('Not assigned yet')
                        ->weight('bold'),

                    // The vendor's own delivery cost: only on orders from before
                    // GadgetPlug paid the logistics company itself.
                    TextEntry::make('delivery_cost')
                        ->label('What You Pay to Deliver')
                        ->placeholder('Not recorded yet')
                        ->money('NGN')
                        ->visible(fn (Order $record) => filled($record->delivery_cost)),

                    TextEntry::make('posted_at')
                        ->label('Have You Paid It?')
                        ->placeholder('Not yet')
                        ->formatStateUsing(fn ($state) => $state ? 'Yes, paid' : 'Not yet')
                        ->badge()
                        ->color(fn ($state) => $state ? 'success' : 'warning')
                        ->visible(fn (Order $record) => filled($record->delivery_cost)),
                ]),

            // Who moved the order and when — written by OrderStatusService.
            Section::make('Fulfilment')
                ->columns(2)
                ->schema([
                    TextEntry::make('packed_at')
                        ->label('Packed')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->helperText(fn (Order $record) => $record->packedBy?->name),

                    TextEntry::make('handed_over_at')
                        ->label('Handed to rider')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->helperText(fn (Order $record) => $record->handedOverBy?->name),

                    TextEntry::make('delivered_at')
                        ->label('Delivered')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->helperText(fn (Order $record) => $record->delivered_override_reason
                            ? 'Marked by '.($record->deliveredBy?->name ?? 'GadgetPlug').': '.$record->delivered_override_reason
                            : null),

                    TextEntry::make('payment_collected_at')
                        ->label('Payment received by GadgetPlug')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->visible(fn (Order $record) => $record->payment_method === 'pay_on_delivery'),

                    TextEntry::make('return_reason')
                        ->label('Delivery failed — returned to store')
                        ->columnSpanFull()
                        ->visible(fn (Order $record) => filled($record->return_reason))
                        ->helperText(fn (Order $record) => trim(($record->returned_at?->format('d M Y, g:ia') ?? '').' '.($record->returnedBy?->name ? '· '.$record->returnedBy->name : ''))),

                    TextEntry::make('cancel_reason')
                        ->label('Cancelled')
                        ->columnSpanFull()
                        ->visible(fn (Order $record) => filled($record->cancel_reason))
                        ->helperText(fn (Order $record) => $record->cancelled_at?->format('d M Y, g:ia')),
                ]),

            // GadgetPlug's side of the delivery: admin panel only.
            Section::make('Dispatch')
                ->columns(2)
                ->visible(fn () => filament()->getCurrentPanel()?->getId() === 'admin')
                ->schema([
                    TextEntry::make('fee_share')
                        ->label('Delivery fee carried by this order')
                        ->getStateUsing(fn (Order $record) => $record->deliveryFeeShare())
                        ->money('NGN')
                        ->helperText('The checkout\'s fee is carried by its first part.'),

                    TextEntry::make('company_cost')
                        ->label('What we pay the company')
                        ->money('NGN')
                        ->placeholder('Not set'),

                    TextEntry::make('company_paid_at')
                        ->label('Paid to company')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->helperText(fn (Order $record) => $record->companyPaidBy?->name),

                    TextEntry::make('dispatch_assigned_at')
                        ->label('Assigned')
                        ->dateTime('d M Y, g:ia')
                        ->placeholder('Not yet')
                        ->helperText(fn (Order $record) => $record->dispatchAssignedBy?->name),

                    // Never the code itself — only whether one is out there.
                    TextEntry::make('delivery_code_state')
                        ->label('Delivery code')
                        ->getStateUsing(fn (Order $record) => match (true) {
                            $record->delivered_via === 'code'      => 'Used — delivered with the code',
                            $record->isDeliveryCodeLocked()        => 'Locked after '.$record->delivery_code_attempts.' wrong tries',
                            $record->delivery_code_hash !== null   => 'Sent to the customer'.($record->delivery_code_attempts ? ' · '.$record->delivery_code_attempts.' wrong '.\Illuminate\Support\Str::plural('try', $record->delivery_code_attempts) : ''),
                            default                                => 'None yet (sent at handover)',
                        })
                        ->badge()
                        ->color(fn (Order $record) => $record->isDeliveryCodeLocked() ? 'danger' : 'gray'),

                    TextEntry::make('delivered_via')
                        ->label('Delivered by')
                        ->formatStateUsing(fn ($state) => $state === 'code' ? 'Rider, with the customer\'s code' : 'Override')
                        ->placeholder('—'),
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

            Section::make('Activity History')
                ->schema([
                    RepeatableEntry::make('activities')
                        ->label('')
                        ->schema([
                            TextEntry::make('description')
                                ->label('')
                                ->weight('bold')
                                ->formatStateUsing(function ($record) {
                                    $changes = $record->changes();
                                    $attributes = $changes['attributes'] ?? [];

                                    if (array_key_exists('status', $attributes)) {
                                        return 'Status changed to ' . ucfirst(str_replace('_', ' ', $attributes['status']));
                                    }

                                    if (array_key_exists('logistics_company_id', $attributes) || array_key_exists('delivery_person_id', $attributes)) {
                                        return 'Logistics assignment updated';
                                    }

                                    return ucfirst($record->description);
                                }),

                            TextEntry::make('causer.name')
                                ->label('')
                                ->placeholder('System')
                                ->color('gray')
                                ->size('sm'),

                            TextEntry::make('created_at')
                                ->label('')
                                ->dateTime('d M Y, g:ia')
                                ->color('gray')
                                ->size('sm')
                                ->alignEnd(),
                        ])
                        ->columns(3),
                ])
                ->collapsible()
                ->collapsed(fn ($record) => $record->activities->isEmpty()),

            Section::make('Notes')
                ->schema([
                    RepeatableEntry::make('notes')
                        ->label('')
                        ->schema([
                            TextEntry::make('body')
                                ->label('')
                                ->columnSpanFull(),

                            TextEntry::make('user.name')
                                ->label('')
                                ->placeholder('Automated')
                                ->color('gray')
                                ->size('sm'),

                            TextEntry::make('created_at')
                                ->label('')
                                ->since()
                                ->color('gray')
                                ->size('sm')
                                ->alignEnd(),
                        ])
                        ->columns(2),
                ])
                ->collapsible()
                ->collapsed(fn ($record) => $record->notes->isEmpty()),

        ]);
    }
}
