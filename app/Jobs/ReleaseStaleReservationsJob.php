<?php

namespace App\Jobs;

use App\Actions\Inventory\ReleaseReservationAction;
use App\Models\Order;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Frees stock held by an online order that was never dispatched or cancelled
// — a rider who never shows, a customer who goes quiet after paying. Without
// this, that stock sits walled off from every other buyer indefinitely,
// since ReleaseReservationAction otherwise only fires on explicit
// cancellation (OrderObserver).
//
// This does NOT cancel the order — the goods might still be handed over.
// It only lifts the online hold so the stock becomes sellable again (POS or
// another online order); the vendor is notified so a human decides what
// happens to the original order next.
class ReleaseStaleReservationsJob implements ShouldQueue
{
    use Queueable;

    public const STALE_AFTER_HOURS = 24;

    public function handle(): void
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);

        Order::whereNotNull('reserved_at')
            ->whereNull('reservation_released_at')
            ->where('reserved_at', '<=', $cutoff)
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    $this->release($order);
                }
            });
    }

    private function release(Order $order): void
    {
        try {
            DB::transaction(function () use ($order) {
                // Re-check inside the transaction — the sweep runs hourly, a
                // manual cancellation could have released this order (and set
                // reservation_released_at some other way) between the query
                // above and this lock.
                $locked = Order::where('id', $order->id)
                    ->whereNull('reservation_released_at')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return;
                }

                $locked->load('items');

                foreach ($locked->items as $item) {
                    app(ReleaseReservationAction::class)->execute(
                        productId: $item->product_id,
                        quantity: $item->quantity,
                        reference: $locked->reference,
                        description: 'Reservation auto-released — held over '.self::STALE_AFTER_HOURS.'h with no dispatch or cancellation.',
                        orderItemId: $item->id,
                    );
                }

                $locked->update(['reservation_released_at' => now()]);

                $this->notifyVendor($locked);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to auto-release stale reservation for order {$order->id}: ".$e->getMessage());
        }
    }

    private function notifyVendor(Order $order): void
    {
        $order->load('items.vendor.users', 'items.vendor.user');

        $byVendor = $order->items->groupBy('vendor_id');

        foreach ($byVendor as $items) {
            $vendor = $items->first()->vendor;

            if (! $vendor) {
                continue;
            }

            $recipients = $vendor->users()->get()
                ->push($vendor->user)
                ->filter()
                ->unique('id');

            foreach ($recipients as $user) {
                Notification::make()
                    ->title('Reservation expired: #'.$order->reference)
                    ->body('Held for over '.self::STALE_AFTER_HOURS.'h with no dispatch — the stock is now free to sell elsewhere. Confirm whether this order can still be fulfilled.')
                    ->icon('heroicon-o-clock')
                    ->warning()
                    ->sendToDatabase($user);
            }
        }
    }
}
