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

    /** Phone styles in the admin theme: the stage tabs wrap instead of scrolling. */
    public function getPageClasses(): array
    {
        return ['gp-dispatch-page'];
    }

    /**
     * The dispatch stages, in the order an order moves through them.
     *
     * The closure parameter must be called $query: Filament hands the query
     * over by that name, and any other name gets a new, model-less builder
     * from the container instead — which crashed every tab but "All".
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'needs_packing' => Tab::make('Needs packing')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['confirmed', 'paid'])),

            'packed' => Tab::make('Packed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'packed')),

            'with_rider' => Tab::make('With rider')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'shipped')),

            'delivered' => Tab::make('Delivered')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'delivered')),

            // Pay-on-delivery money not yet recorded as with GadgetPlug.
            'payment_pending' => Tab::make('Payment pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('payment_method', 'pay_on_delivery')
                    ->whereIn('status', ['shipped', 'delivered'])
                    ->whereNull('revenue_recognized_at')),

            'returned' => Tab::make('Returned')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'returned')),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}
