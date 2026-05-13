<?php

namespace App\Observers;

use App\Models\Order;
use Filament\Notifications\Notification;

class OrderObserver
{
    public function updated(Order $order): void
    {
        $notifyOn = ['paid', 'confirmed'];

        if (! in_array($order->status, $notifyOn)) {
            return;
        }

        // Only fire once — skip if already in a notified state
        if (in_array($order->getOriginal('status'), $notifyOn)) {
            return;
        }

        $order->load('items.vendor.users', 'items.vendor.user');

        $byVendor = $order->items->groupBy('vendor_id');

        foreach ($byVendor as $vendorId => $items) {
            $vendor = $items->first()->vendor;

            if (! $vendor) {
                continue;
            }

            $itemCount   = (int) $items->sum('quantity');
            $vendorTotal = (float) $items->sum(fn($item) => $item->quantity * $item->unit_price);

            $body = $itemCount . ' item(s) · ₦' . number_format($vendorTotal, 2);

            $recipients = $vendor->users()->get()
                ->push($vendor->user)
                ->filter()
                ->unique('id');

            foreach ($recipients as $user) {
                Notification::make()
                    ->title('New order: #' . $order->reference)
                    ->body($body)
                    ->icon('heroicon-o-shopping-bag')
                    ->success()
                    ->sendToDatabase($user);
            }
        }
    }
}
