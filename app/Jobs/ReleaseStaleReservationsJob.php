<?php

namespace App\Jobs;

use App\Actions\Inventory\ReleaseReservationAction;
use App\Models\Order;
use App\Models\User;
use Filament\Notifications\Notification;
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
//
// Only an order nobody has committed money or a promise to is released: one
// still pending payment. A paid order's stock is the customer's — never freed
// behind their back. A confirmed pay-on-delivery order past the cutoff is not
// released either: GadgetPlug's admins are alerted once, to chase or cancel it.
class ReleaseStaleReservationsJob
{
    public const STALE_AFTER_HOURS = 24;

    public function handle(): void
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);

        Order::whereNotNull('reserved_at')
            ->whereNull('reservation_released_at')
            ->where('reserved_at', '<=', $cutoff)
            ->where('status', 'pending')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    $this->release($order);
                }
            });

        Order::whereNotNull('reserved_at')
            ->whereNull('reservation_released_at')
            ->whereNull('stale_alerted_at')
            ->where('reserved_at', '<=', $cutoff)
            ->where('status', 'confirmed')
            ->where('payment_method', 'pay_on_delivery')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    $this->alertAdmins($order);
                }
            });
    }

    // Once per order: stale_alerted_at is set whether or not anyone was there
    // to receive it, so the hourly sweep never repeats itself.
    private function alertAdmins(Order $order): void
    {
        try {
            $order->updateQuietly(['stale_alerted_at' => now()]);

            // Read raw, like User::isSuperAdmin(): the roles relation filters by
            // whichever team is current, and a job has none it should trust.
            $admins = User::whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->whereNull('model_has_roles.team_id')
                ->where('roles.name', 'super_admin')
                ->pluck('model_has_roles.model_id'))
                ->get();

            foreach ($admins as $admin) {
                Notification::make()
                    ->title('Pay-on-delivery order waiting: #'.$order->reference)
                    ->body('Confirmed over '.self::STALE_AFTER_HOURS.'h ago and not yet handed to a rider. Its stock is still held — chase the store or cancel the order.')
                    ->icon('heroicon-o-clock')
                    ->warning()
                    ->sendToDatabase($admin);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to alert admins about stale order {$order->id}: ".$e->getMessage());
        }
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

                $locked->load('items.product');

                foreach ($locked->items as $item) {
                    // A resold line never held a reservation; releasing it
                    // would only write a phantom ledger row on the reseller.
                    if (! $item->tracksStock()) {
                        continue;
                    }

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
