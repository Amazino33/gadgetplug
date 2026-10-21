<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\DispatchActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Vendor\Resources\Orders\Schemas\OrderInfolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

// GadgetPlug's side of an order — reuses the vendor panel's order infolist for
// the details (plus a dispatch section only the admin panel shows). The store
// packs and hands over from the vendor panel; what happens after that is
// done here: assigning a logistics company, what we pay it, the money
// collected on a pay-on-delivery order, delivery by override, failed
// delivery, and cancellation. Every action is DispatchActions, the same set
// the dispatch board rows use.
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return DispatchActions::all();
    }
}
