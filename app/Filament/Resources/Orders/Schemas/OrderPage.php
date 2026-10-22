<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\DeliveryMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderLocation;
use App\Services\Orders\OrderMessages;
use App\Services\Orders\OrderProgress;
use App\Support\Naira;
use App\Support\OrderBadges;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Str;

/**
 * GadgetPlug's order page (admin / dispatch): summary and progress at the top,
 * then cards — customer, items, money, map on the left; logistics, fulfilment
 * and messages on the right (stacked on a phone). The vendor panel keeps its
 * own order page (Vendor\Resources\Orders\Schemas\OrderInfolist).
 */
class OrderPage
{
    /** @param  array<int, Action>  $messageActions  "Send message" and "Open WhatsApp chat", owned by the page */
    public static function configure(Schema $schema, array $messageActions = []): Schema
    {
        // One column: the page's own layout below is a 1-or-3 column grid, not
        // the two columns a view page's infolist gets by default.
        return $schema->columns(1)->components([
            self::summary(),
            self::progress(),

            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make([
                    self::customer(),
                    self::resellerPurchase(),
                    self::items(),
                    self::money(),
                    self::map(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::logistics(),
                    self::fulfilment(),
                    self::messages($messageActions),
                ])->columnSpan(['lg' => 1]),
            ]),

            self::notes(),
            self::activity(),
        ]);
    }

    private static function summary(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(['default' => 2, 'md' => 4])->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->size(TextSize::Large)
                        ->formatStateUsing(fn (?string $state): string => OrderBadges::statusLabel($state))
                        ->color(fn (?string $state): string => OrderBadges::statusColor($state)),

                    TextEntry::make('payment_state')
                        ->label('Payment')
                        ->badge()
                        ->size(TextSize::Large)
                        ->state(fn (Order $record): string => OrderBadges::paymentState($record))
                        ->color(fn (string $state): string => OrderBadges::paymentStateColor($state)),

                    TextEntry::make('order_total')
                        ->label('Total')
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large)
                        ->state(fn (Order $record): string => Naira::format((float) $record->total_amount + $record->deliveryFeeShare())),

                    TextEntry::make('created_at')
                        ->label('Placed')
                        ->dateTime('d M Y, g:ia'),

                    TextEntry::make('location_check')
                        ->hiddenLabel()
                        ->badge()
                        ->color('warning')
                        ->icon('heroicon-m-exclamation-triangle')
                        ->state('Location doesn\'t match LGA')
                        ->helperText(fn (Order $record): string => 'The customer\'s shared pin is '.app(OrderLocation::class)->mismatchKm($record).' km from '.$record->checkout?->lga?->name.' (straight-line). Check the address before sending a rider.')
                        ->visible(fn (Order $record): bool => app(OrderLocation::class)->mismatched($record))
                        ->columnSpanFull(),
                ]),
            ]);
    }

    private static function progress(): Section
    {
        $step = fn (int $index) => TextEntry::make("progress_{$index}")
            ->label(fn (Order $record): string => self::step($record, $index)['label'])
            ->state(fn (Order $record): string => self::stepWhen(self::step($record, $index)))
            ->helperText(fn (Order $record): ?string => self::step($record, $index)['by'])
            ->icon(fn (Order $record): string => match (self::step($record, $index)['state']) {
                OrderProgress::DONE    => 'heroicon-m-check-circle',
                OrderProgress::CURRENT => 'heroicon-m-arrow-right-circle',
                default                => 'heroicon-o-clock',
            })
            ->iconColor(fn (Order $record): string => match (self::step($record, $index)['state']) {
                OrderProgress::DONE    => 'success',
                OrderProgress::CURRENT => 'primary',
                default                => 'gray',
            })
            ->weight(fn (Order $record): ?FontWeight => self::step($record, $index)['state'] === OrderProgress::CURRENT ? FontWeight::Bold : null)
            ->color(fn (Order $record): ?string => self::step($record, $index)['state'] === OrderProgress::UPCOMING ? 'gray' : null);

        return Section::make('Progress')
            ->compact()
            ->schema([
                Grid::make(['default' => 1, 'sm' => 5])->schema([
                    $step(0), $step(1), $step(2), $step(3), $step(4),
                ]),

                TextEntry::make('progress_branch')
                    ->label(fn (Order $record): string => app(OrderProgress::class)->branch($record)['label'] ?? '')
                    ->state(fn (Order $record): string => self::stepWhen(app(OrderProgress::class)->branch($record) ?? ['at' => null]))
                    ->helperText(fn (Order $record): ?string => collect([
                        app(OrderProgress::class)->branch($record)['by'] ?? null,
                        app(OrderProgress::class)->branch($record)['reason'] ?? null,
                    ])->filter()->implode(' — ') ?: null)
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->iconColor('danger')
                    ->weight(FontWeight::Bold)
                    ->visible(fn (Order $record): bool => app(OrderProgress::class)->branch($record) !== null),
            ]);
    }

    private static function customer(): Section
    {
        return Section::make('Customer')
            ->icon('heroicon-o-user')
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                TextEntry::make('customer_name')->label('Name')->weight(FontWeight::SemiBold),

                TextEntry::make('customer_phone')
                    ->label('Phone')
                    ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^\d+]/', '', $state) : null)
                    ->color('primary')
                    ->icon('heroicon-m-phone')
                    ->copyable(),

                TextEntry::make('customer_email')->label('Email')->placeholder('—'),

                TextEntry::make('area')
                    ->label('Area')
                    ->state(fn (Order $record): string => collect([$record->checkout?->lga?->name ?? $record->local_government, $record->checkout?->lga?->state?->name])->filter()->implode(', ') ?: '—'),

                TextEntry::make('shipping_address')
                    ->label('Delivery address')
                    ->columnSpanFull()
                    ->copyable(),

                TextEntry::make('shared_location')
                    ->label('Location')
                    ->badge()
                    ->state(fn (Order $record): string => $record->checkout?->customer_latitude !== null ? 'Shared at checkout' : 'Not shared')
                    ->color(fn (string $state): string => $state === 'Not shared' ? 'gray' : 'success'),
            ]);
    }

    /** A reseller buying for their own customer, paid from their wallet. */
    private static function resellerPurchase(): Section
    {
        return Section::make('Reseller purchase')
            ->icon('heroicon-o-user-group')
            ->columns(['default' => 1, 'sm' => 3])
            ->visible(fn (Order $record): bool => $record->payment_method === 'wallet')
            ->schema([
                TextEntry::make('reseller_affiliate_code')
                    ->label('Affiliate')
                    ->state(fn (Order $record): string => \App\Models\Affiliate::where('user_id', $record->user_id)->value('code') ?? '—'),

                TextEntry::make('reseller_affiliate_name')
                    ->label('Name')
                    ->state(fn (Order $record): string => $record->user?->name ?? '—'),

                TextEntry::make('walletDebit.amount')
                    ->label('Wallet debited')
                    ->weight(FontWeight::Bold)
                    ->color('danger')
                    ->formatStateUsing(fn ($state): string => $state !== null ? Naira::format(abs((float) $state)) : '—'),
            ]);
    }

    private static function items(): Section
    {
        return Section::make('Items')
            ->icon('heroicon-o-shopping-bag')
            ->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    // A compact row per item when the card is narrow (admin theme).
                    ->extraAttributes(['class' => 'gp-compact-rows'])
                    ->table([
                        TableColumn::make('Item'),
                        TableColumn::make('Qty'),
                        TableColumn::make('Price'),
                        TableColumn::make('Total'),
                    ])
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('Item')
                            ->weight(FontWeight::SemiBold)
                            ->helperText(fn (OrderItem $record): ?string => ($online = $record->product?->online_name) && $online !== $record->product?->name
                                ? 'Online: '.$online
                                : null),
                        TextEntry::make('quantity')->label('Qty'),
                        TextEntry::make('unit_price')->label('Price')->formatStateUsing(fn ($state): string => Naira::format($state)),
                        TextEntry::make('line_total')
                            ->label('Total')
                            ->state(fn (OrderItem $record): string => Naira::format((float) $record->quantity * (float) $record->unit_price)),
                    ]),
            ]);
    }

    private static function money(): Section
    {
        return Section::make('Money')
            ->icon('heroicon-o-banknotes')
            ->columns(['default' => 2, 'md' => 3])
            ->schema([
                TextEntry::make('money_items')
                    ->label('Items')
                    ->state(fn (Order $record): string => Naira::format($record->total_amount)),

                TextEntry::make('money_fee')
                    ->label('Delivery fee')
                    ->state(fn (Order $record): string => Naira::format($record->deliveryFeeShare()))
                    ->helperText(fn (Order $record): ?string => (int) ($record->checkout?->orders()->count() ?? 1) > 1 ? 'Carried by the checkout\'s first part.' : null),

                TextEntry::make('money_total')
                    ->label('Total')
                    ->weight(FontWeight::Bold)
                    ->state(fn (Order $record): string => Naira::format((float) $record->total_amount + $record->deliveryFeeShare())),

                TextEntry::make('money_collect')
                    ->label('To collect at the door')
                    ->weight(FontWeight::Bold)
                    ->state(fn (Order $record): string => $record->amountToCollect() > 0 ? Naira::format($record->amountToCollect()) : 'Nothing — paid')
                    ->color(fn (Order $record): string => $record->amountToCollect() > 0 ? 'warning' : 'success'),

                TextEntry::make('money_fee_vs_cost')
                    ->label('Fee vs cost')
                    ->state(fn (Order $record): string => $record->company_cost === null
                        ? 'Cost not set'
                        : (($margin = $record->deliveryFeeShare() - (float) $record->company_cost) >= 0 ? '+' : '').Naira::format($margin))
                    ->helperText(fn (Order $record): ?string => $record->company_cost !== null ? 'We pay the company '.Naira::format($record->company_cost) : null)
                    ->color(fn (Order $record): string => $record->company_cost === null ? 'gray' : (($record->deliveryFeeShare() - (float) $record->company_cost) >= 0 ? 'success' : 'danger')),
            ]);
    }

    private static function map(): Section
    {
        return Section::make('Map')
            ->icon('heroicon-o-map')
            ->description('Straight-line distance; the road is longer.')
            ->schema([
                ViewEntry::make('route_map')
                    ->hiddenLabel()
                    ->view('filament.admin.orders.map')
                    ->state(fn (Order $record): array => app(OrderLocation::class)->mapData($record)),

                Actions::make([
                    // Google Maps' public directions link: no key, nothing paid.
                    Action::make('directions')
                        ->label('Open directions in Google Maps')
                        ->icon('heroicon-m-arrow-top-right-on-square')
                        ->color('gray')
                        ->size('lg')
                        ->url(fn (Order $record): ?string => app(OrderLocation::class)->directionsUrl($record), shouldOpenInNewTab: true)
                        ->visible(fn (Order $record): bool => app(OrderLocation::class)->directionsUrl($record) !== null),
                ])->fullWidth(),
            ]);
    }

    private static function logistics(): Section
    {
        return Section::make('Logistics')
            ->icon('heroicon-o-truck')
            ->schema([
                TextEntry::make('logisticsCompany.name')
                    ->label('Company')
                    ->placeholder('Not assigned yet')
                    ->weight(FontWeight::SemiBold),

                TextEntry::make('rider')
                    ->label('Rider')
                    ->state(fn (Order $record): ?string => $record->rider_name
                        ? trim($record->rider_name.' '.($record->rider_phone ? '· '.$record->rider_phone : ''))
                        : $record->deliveryPerson?->name)
                    ->placeholder('Not named'),

                TextEntry::make('link_state')
                    ->label('Rider link')
                    ->badge()
                    ->state(fn (Order $record): string => match (true) {
                        ! $record->logistics_company_id                                      => 'Not sent',
                        $record->dispatch_assigned_at?->lt(now()->subHours(48)) ?? false     => 'Expired — resend if needed',
                        default                                                              => 'Sent',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Sent'     => 'success',
                        'Not sent' => 'gray',
                        default    => 'warning',
                    })
                    ->helperText(fn (Order $record): ?string => $record->dispatch_assigned_at
                        ? 'Assigned '.$record->dispatch_assigned_at->format('d M, g:ia').($record->dispatchAssignedBy ? ' by '.$record->dispatchAssignedBy->name : '')
                        : null),

                // Never the code itself — only whether one is out there.
                TextEntry::make('code_state')
                    ->label('Delivery code')
                    ->badge()
                    ->state(fn (Order $record): string => match (true) {
                        $record->delivered_via === 'code'    => 'Used',
                        $record->isDeliveryCodeLocked()      => 'Locked',
                        $record->delivery_code_hash !== null => 'Sent to customer',
                        default                              => 'Not sent yet',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Locked'           => 'danger',
                        'Used'             => 'success',
                        'Sent to customer' => 'info',
                        default            => 'gray',
                    })
                    ->helperText(fn (Order $record): ?string => $record->isDeliveryCodeLocked()
                        ? 'Locked after '.$record->delivery_code_attempts.' wrong tries — send a new code.'
                        : ($record->delivery_code_attempts ? $record->delivery_code_attempts.' wrong '.Str::plural('try', $record->delivery_code_attempts) : 'Sent at handover')),

                TextEntry::make('company_paid_at')
                    ->label('Paid to company')
                    ->dateTime('d M Y, g:ia')
                    ->placeholder('Not yet')
                    ->helperText(fn (Order $record): ?string => $record->companyPaidBy?->name)
                    ->visible(fn (Order $record): bool => $record->company_cost !== null),
            ]);
    }

    private static function fulfilment(): Section
    {
        return Section::make('Fulfilment')
            ->icon('heroicon-o-clipboard-document-check')
            ->schema([
                TextEntry::make('payment_collected_at')
                    ->label('Payment received by GadgetPlug')
                    ->dateTime('d M Y, g:ia')
                    ->placeholder('Not yet')
                    ->helperText(fn (Order $record): ?string => $record->paymentCollectedBy?->name)
                    ->visible(fn (Order $record): bool => $record->payment_method === 'pay_on_delivery'),

                TextEntry::make('delivered_override_reason')
                    ->label('Marked delivered by override')
                    ->helperText(fn (Order $record): ?string => $record->deliveredBy?->name)
                    ->visible(fn (Order $record): bool => filled($record->delivered_override_reason)),

                TextEntry::make('return_reason')
                    ->label('Delivery failed — returned to store')
                    ->visible(fn (Order $record): bool => filled($record->return_reason)),

                TextEntry::make('cancel_reason')
                    ->label('Cancelled')
                    ->visible(fn (Order $record): bool => filled($record->cancel_reason)),

                TextEntry::make('stock_from')
                    ->label('Stock held at')
                    ->state(fn (Order $record): string => \App\Services\Orders\DispatchMessages::pickupStores($record)
                        ->map(fn ($store) => trim(($store->vendor?->name ? $store->vendor->name.' — ' : '').$store->name))
                        ->implode(', ') ?: '—'),
            ]);
    }

    /** @param  array<int, Action>  $actions */
    private static function messages(array $actions): Section
    {
        return Section::make('Messages')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->schema([
                Actions::make($actions)->fullWidth(),

                RepeatableEntry::make('message_history')
                    ->hiddenLabel()
                    ->state(fn (Order $record) => app(OrderMessages::class)->history($record)->all())
                    ->placeholder('No messages about this order yet.')
                    ->schema([
                        TextEntry::make('recipient_type')
                            ->hiddenLabel()
                            ->weight(FontWeight::SemiBold)
                            ->formatStateUsing(fn (?string $state, DeliveryMessage $record): string => Str::headline((string) $state)
                                .' · '.($record->channel === 'sms' ? 'SMS' : 'WhatsApp')
                                .($record->order && $record->order->reference !== null ? ' · #'.$record->order->reference : ''))
                            ->helperText(fn (DeliveryMessage $record): string => Str::limit((string) $record->body, 120)),

                        TextEntry::make('status')
                            ->hiddenLabel()
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'link_generated' => 'Link made',
                                default          => ucfirst((string) $state),
                            })
                            ->color(fn (?string $state): string => match ($state) {
                                'sent'           => 'success',
                                'failed'         => 'danger',
                                'queued', 'held' => 'warning',
                                default          => 'gray',
                            })
                            ->helperText(fn (DeliveryMessage $record): string => $record->created_at?->format('d M, g:ia') ?? ''),
                    ])
                    ->columns(['default' => 2]),
            ]);
    }

    private static function notes(): Section
    {
        return Section::make('Notes')
            ->collapsible()
            ->collapsed(fn (Order $record): bool => $record->notes->isEmpty())
            ->schema([
                RepeatableEntry::make('notes')
                    ->hiddenLabel()
                    ->placeholder('No notes.')
                    ->schema([
                        TextEntry::make('body')->hiddenLabel()->columnSpanFull(),
                        TextEntry::make('user.name')->hiddenLabel()->placeholder('Automated')->color('gray'),
                        TextEntry::make('created_at')->hiddenLabel()->since()->color('gray'),
                    ])
                    ->columns(['default' => 2]),
            ]);
    }

    private static function activity(): Section
    {
        return Section::make('Activity')
            ->collapsible()
            ->collapsed()
            ->schema([
                RepeatableEntry::make('activities')
                    ->hiddenLabel()
                    ->placeholder('Nothing recorded yet.')
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->weight(FontWeight::SemiBold)
                            ->formatStateUsing(function ($record): string {
                                $status = $record->changes()['attributes']['status'] ?? null;

                                return $status ? 'Status: '.OrderBadges::statusLabel($status) : ucfirst((string) $record->description);
                            }),
                        TextEntry::make('causer.name')->hiddenLabel()->placeholder('System')->color('gray'),
                        TextEntry::make('created_at')->hiddenLabel()->dateTime('d M Y, g:ia')->color('gray'),
                    ])
                    ->columns(['default' => 1, 'sm' => 3]),
            ]);
    }

    /** @return array{key: string, label: string, state: string, at: mixed, by: ?string} */
    /**
     * Worked out once per render — five entries read it — and again after an
     * action changes the order (its updated_at moves).
     */
    private static function step(Order $record, int $index): array
    {
        static $cache = null;
        $cache ??= new \WeakMap;

        $version = $record->updated_at?->toIso8601String();

        if (! isset($cache[$record]) || $cache[$record]['version'] !== $version) {
            $cache[$record] = ['version' => $version, 'steps' => app(OrderProgress::class)->steps($record)];
        }

        return $cache[$record]['steps'][$index];
    }

    private static function stepWhen(array $step): string
    {
        return match (true) {
            isset($step['at']) && $step['at'] => $step['at']->format('d M, g:ia'),
            ($step['state'] ?? null) === OrderProgress::UPCOMING => 'Not yet',
            default => 'Done',
        };
    }
}
