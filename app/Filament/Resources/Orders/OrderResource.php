<?php

namespace App\Filament\Resources\Orders;

use App\Models\Lga;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\Orders\OrderLocation;
use App\Services\Orders\OrderStatusService;
use App\Support\Naira;
use App\Support\OrderBadges;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The dispatch board: every online order, for GadgetPlug's dispatch staff
 * (manage_dispatch — the dispatcher role; super_admin passes). Tabs by stage,
 * filters by vendor, store, area, payment and company, and per order the
 * delivery fee it carries against what we pay the logistics company.
 */
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
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasGlobalPermission('manage_dispatch'));
    }

    // Shield's View:Order permissions are vendor-side; dispatch access is the
    // gate here, for the list and the order page alike.
    public static function canViewAny(): bool    { return static::canAccess(); }
    public static function canView(Model $record): bool { return static::canAccess(); }
    public static function canCreate(): bool   { return false; }
    public static function canEdit($r): bool   { return false; }
    public static function canDelete($r): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['items.product', 'items.vendor', 'user', 'checkout.lga', 'logisticsCompany'])
            // Per checkout: which part carries the delivery fee (REF-1), how
            // many parts it has, and what all of them cost to deliver.
            ->select('orders.*')
            ->addSelect([
                'checkout_first_order_id' => DB::table('orders as parts')
                    ->selectRaw('MIN(parts.id)')
                    ->whereColumn('parts.checkout_id', 'orders.checkout_id'),
                'checkout_parts_count' => DB::table('orders as parts')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('parts.checkout_id', 'orders.checkout_id'),
                'checkout_parts_cost' => DB::table('orders as parts')
                    ->selectRaw('SUM(parts.company_cost)')
                    ->whereColumn('parts.checkout_id', 'orders.checkout_id'),
            ])
            ->latest();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Card styles below lg in the admin theme (no header row, no actions column).
            ->extraAttributes(['class' => 'gp-card-table'])
            ->columns([
                // Phone and tablet: one card per order with its next step as a
                // button. Every column below is visibleFrom('lg'), so a laptop
                // gets the table; search, tabs and filters are the same either way.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('filament.admin.orders.dispatch-card')
                    ->disabledClick()
                    ->hiddenFrom('lg'),

                TextColumn::make('reference')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    // The store, and for a checkout in parts which part this is.
                    ->description(fn (Order $record) => collect([
                        $record->items->first()?->vendor?->name,
                        (int) $record->checkout_parts_count > 1
                            ? 'part of '.$record->checkout?->reference.' ('.$record->checkout_parts_count.' parts)'
                            : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->visibleFrom('lg'),

                // The customer's shared pin is far from the LGA they chose.
                TextColumn::make('location_check')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->state(fn (Order $record): ?string => app(OrderLocation::class)->mismatched($record) ? "Location doesn't match LGA" : null)
                    ->wrap()
                    ->visibleFrom('lg'),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Order $record) => collect([
                        $record->checkout?->lga?->name ?? $record->local_government,
                        $record->customer_phone,
                    ])->filter()->implode(' · '))
                    ->visibleFrom('lg'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => OrderBadges::statusLabel($state))
                    ->color(fn ($state) => OrderBadges::statusColor($state))
                    ->visibleFrom('lg'),

                TextColumn::make('payment_state')
                    ->label('Payment')
                    ->badge()
                    ->getStateUsing(fn (Order $record) => OrderBadges::paymentState($record))
                    ->color(fn (string $state) => OrderBadges::paymentStateColor($state))
                    ->visibleFrom('lg'),

                TextColumn::make('logisticsCompany.name')
                    ->label('Company')
                    ->placeholder('Not assigned')
                    ->description(fn (Order $record) => $record->rider_name
                        ? trim($record->rider_name.' '.($record->rider_phone ? '· '.$record->rider_phone : ''))
                        : null)
                    ->visibleFrom('2xl'),

                // What delivering this order earned or lost: its share of the
                // checkout's fee (carried by REF-1) against what we pay the
                // company. For a checkout in parts, the whole checkout's
                // figures are underneath — that is the real comparison.
                TextColumn::make('fee_vs_cost')
                    ->label('Fee vs cost')
                    ->getStateUsing(fn (Order $record) => $record->company_cost === null
                        ? null
                        : round($record->deliveryFeeShare() - (float) $record->company_cost, 2))
                    ->formatStateUsing(fn ($state, Order $record) => 'Fee '.Naira::format($record->deliveryFeeShare())
                        .' · Cost '.Naira::format($record->company_cost)
                        .' · '.((float) $state >= 0 ? '+' : '').Naira::format($state))
                    ->placeholder(fn (Order $record) => 'Fee '.Naira::format($record->deliveryFeeShare()).' · cost not set')
                    ->color(fn ($state) => $state === null ? 'gray' : ((float) $state >= 0 ? 'success' : 'danger'))
                    ->description(fn (Order $record) => (int) $record->checkout_parts_count > 1 && $record->checkout
                        ? 'Whole checkout: fee '.Naira::format($record->checkout->delivery_fee)
                            .' vs cost '.Naira::format($record->checkout_parts_cost)
                        : null)
                    ->visibleFrom('2xl'),

                IconColumn::make('company_paid_at')
                    ->label('Paid to company')
                    ->boolean()
                    ->getStateUsing(fn (Order $record) => $record->company_cost === null ? null : $record->company_paid_at !== null)
                    ->visibleFrom('2xl'),

                TextColumn::make('created_at')->label('Placed')->dateTime('d M Y, H:i')->sortable()->visibleFrom('2xl'),
            ])
            ->emptyStateHeading('No orders here')
            ->emptyStateDescription('Orders in this stage show up here as they come in.')
            ->emptyStateIcon('heroicon-o-truck')
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatusService::LABELS),

                SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn () => Vendor::pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $vendorId) => $q->whereHas('items', fn (Builder $q2) => $q2->where('vendor_id', $vendorId))
                    )),

                // The branch holding the order's stock.
                SelectFilter::make('store_id')
                    ->label('Store')
                    ->options(fn () => Store::with('vendor')->get()->mapWithKeys(fn (Store $s) => [$s->id => ($s->vendor?->name ?? '').' — '.$s->name]))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $storeId) => $q->whereHas('items.storeAllocations', fn (Builder $a) => $a->where('store_id', $storeId))
                    )),

                SelectFilter::make('lga_id')
                    ->label('Area')
                    ->options(fn () => Lga::query()
                        ->whereIn('id', DB::table('checkouts')->whereNotNull('lga_id')->distinct()->pluck('lga_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $lgaId) => $q->whereHas('checkout', fn (Builder $c) => $c->where('lga_id', $lgaId))
                    )),

                SelectFilter::make('payment_method')
                    ->label('Payment')
                    ->options([
                        'paystack'        => 'Paystack',
                        'pay_on_delivery' => 'Pay on Delivery',
                        'wallet'          => 'Reseller (Wallet)',
                    ]),

                SelectFilter::make('logistics_company_id')
                    ->label('Company')
                    ->options(fn () => LogisticsCompany::platform()->orderBy('name')->pluck('name', 'id')),

                TernaryFilter::make('company_paid')
                    ->label('Paid to company')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('company_paid_at'),
                        false: fn (Builder $q) => $q->whereNotNull('company_cost')->whereNull('company_paid_at'),
                    ),
            ])
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $record) => Pages\ViewOrder::getUrl(['record' => $record])),
                ActionGroup::make(DispatchActions::all()),
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
