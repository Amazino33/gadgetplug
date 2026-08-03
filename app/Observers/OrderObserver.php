<?php

namespace App\Observers;

use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    // Order status values that have a matching customer-facing template. Only
    // 'shipped' and 'delivered' are actually reachable via the status-change UI
    // in this app (see ViewOrder's updateStatus action) — 'confirmed' is set by
    // the Paystack webhook, not a vendor action, and 'out_for_delivery' isn't a
    // status this app's orders table has, so neither is wired here.
    private const CUSTOMER_STATUS_TEMPLATES = [
        'shipped'   => 'customer_dispatched',
        'delivered' => 'customer_delivered',
    ];

    public function updated(Order $order): void
    {
        $notifyOn = ['paid', 'confirmed'];

        if (in_array($order->status, $notifyOn) && ! in_array($order->getOriginal('status'), $notifyOn)) {
            $this->notifyVendorOfNewOrder($order);
        }

        $this->applyStatusTransitionSideEffects($order);
        $this->notifyCustomerOfStatusChange($order);
    }

    // Centralized here rather than in each Filament action (ViewOrder, the Orders
    // list, Order Items) so stock always moves correctly no matter which of the
    // several UI entry points changed the status — a status update previously
    // bypassed physical stock deduction/release entirely unless made from the
    // order's own detail page.
    private function applyStatusTransitionSideEffects(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $userId = auth()->id();

        if ($order->status === 'shipped') {
            $order->load('items');
            foreach ($order->items as $item) {
                try {
                    app(DispatchStockAction::class)->execute(
                        productId:   $item->product_id,
                        quantity:    $item->quantity,
                        userId:      $userId,
                        reference:   $order->reference,
                        description: 'Physical deduction — order handed to rider.',
                    );
                } catch (\Exception $e) {
                    Log::error("Dispatch stock failed for order {$order->id}: " . $e->getMessage());
                }
            }
        }

        if ($order->status === 'cancelled') {
            $order->load('items');
            foreach ($order->items as $item) {
                try {
                    app(ReleaseReservationAction::class)->execute(
                        productId:   $item->product_id,
                        quantity:    $item->quantity,
                        userId:      $userId,
                        reference:   $order->reference,
                        description: 'Reservation released — order cancelled.',
                    );
                } catch (\Exception $e) {
                    Log::error("Release reservation failed for order {$order->id}: " . $e->getMessage());
                }
            }
        }
    }

    private function notifyVendorOfNewOrder(Order $order): void
    {
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

    private function notifyCustomerOfStatusChange(Order $order): void
    {
        if ($order->skipCustomerNotification) {
            return;
        }

        if (! $order->wasChanged('status')) {
            return;
        }

        $templateKey = self::CUSTOMER_STATUS_TEMPLATES[$order->status] ?? null;

        if (! $templateKey) {
            return;
        }

        // Orders have no direct vendor_id — resolve it via items, same as tapActivity().
        $vendorId = $order->items()->value('vendor_id');

        if (! $vendorId) {
            return;
        }

        $template = MessageTemplate::query()
            ->where('vendor_id', $vendorId)
            ->where('key', $templateKey)
            ->where('recipient_type', 'customer')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return;
        }

        $message = DeliveryMessage::create([
            'vendor_id'      => $vendorId,
            'order_id'       => $order->id,
            'recipient_type' => 'customer',
            'channel'        => $template->channel,
            'to_number'      => $order->customer_phone,
            'body'           => app(TemplateRenderer::class)->render($template->body, TemplateRenderer::contextForOrder($order)),
            'status'         => 'queued',
        ]);

        app(MessagingService::class)->send($message);
    }
}
