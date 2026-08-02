<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Vendor\Resources\Orders\Schemas\OrderInfolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

// Read-only oversight view for super-admins — reuses the same infolist
// layout already built for the vendor panel's order page (order details,
// customer, logistics assignment, items) rather than duplicating it.
// No header actions here: admin can look, not act on a vendor's order.
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }
}
