<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Dispatch';

    /** The dispatch stages, in the order an order moves through them. */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'needs_packing' => Tab::make('Needs packing')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('status', ['confirmed', 'paid'])),

            'packed' => Tab::make('Packed')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'packed')),

            'with_rider' => Tab::make('With rider')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'shipped')),

            'delivered' => Tab::make('Delivered')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'delivered')),

            // Pay-on-delivery money not yet recorded as with GadgetPlug.
            'payment_pending' => Tab::make('Payment pending')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('payment_method', 'pay_on_delivery')
                    ->whereIn('status', ['shipped', 'delivered'])
                    ->whereNull('revenue_recognized_at')),

            'returned' => Tab::make('Returned')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'cancelled')),
        ];
    }
}
