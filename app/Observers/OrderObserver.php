<?php

namespace App\Observers;

use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\StorekeeperNotifier;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Meta\MetaConversionsService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    // Order status values that have a matching customer-facing template.
    //
    // 'paid' and 'confirmed' both mean "money is settled, the order is real" and
    // both send the order-received acknowledgement: Paystack lands on 'paid' via
    // the callback, pay-on-delivery lands on 'confirmed' at checkout. Deliberately
    // not fired on 'pending' — a Paystack order sits at 'pending' from the moment
    // the form is submitted until payment actually clears, so acknowledging there
    // would thank people for abandoned checkouts.
    //
    // 'out_for_delivery' isn't a status this app's orders table uses; its template
    // exists for vendors who send that message by hand from the order page.
    //
    // No 'shipped' entry: at handover the customer gets ONE message — "on its
    // way" with their delivery code — sent by OrderStatusService/DispatchMessages
    // (customer_delivery_code). customer_dispatched stays for sending by hand.
    private const CUSTOMER_STATUS_TEMPLATES = [
        'paid'      => 'customer_received',
        'confirmed' => 'customer_received',
        'delivered' => 'customer_delivered',
    ];

    public function updated(Order $order): void
    {
        $notifyOn = ['paid', 'confirmed'];

        if (in_array($order->status, $notifyOn) && ! in_array($order->getOriginal('status'), $notifyOn)) {
            $this->notifyVendorOfNewOrder($order);
        }

        // Stock, revenue, supplier payables and commissions move in
        // OrderStatusService with the status itself. What stays here only
        // tells people and Meta that it changed.
        $this->applyMetaConversionEvents($order);
        $this->notifyCustomerOfStatusChange($order);
        $this->notifyStorekeeperOfStatusChange($order);
    }

    // Server-side CAPI copy of Purchase — fires on the same "money is settled"
    // transition CUSTOMER_STATUS_TEMPLATES above already uses (confirmed for
    // POD, paid for Paystack), keyed on the checkout's reference so Meta
    // dedupes it against the browser-side copy checkout's success screen
    // fires separately. Deliberately NOT tied to the customer's browser
    // completing that redirect back — if a Paystack payment clears but the
    // customer never returns, this still reaches Meta; recovering exactly
    // that kind of loss is the point of having a server-side copy at all.
    //
    // PaymentConfirmed is a separate custom event, POD only, fired on the
    // same 'confirmed' transition (there's no other "cash collected"
    // checkpoint in this app today — see Prompt recon) — kept as its own
    // event_id/name so it's never confused with or counted as Purchase.
    private function applyMetaConversionEvents(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        if (! in_array($order->status, ['confirmed', 'paid'], true)) {
            return;
        }

        try {
            // Once per checkout, not per child order: whichever child order
            // settles first claims the checkout's Purchase, atomically, and
            // reports the whole basket under the checkout's reference — the
            // same event_id the success screen's browser pixel uses. The rest
            // of the checkout's orders send nothing. An order with no checkout
            // (created outside the flow) is a basket of one, as before.
            $checkout = $order->checkout;

            if ($checkout) {
                if (! $checkout->claimPurchaseTracking()) {
                    return;
                }

                $checkout->load('orders.items.product');
                $items     = $checkout->orders->flatMap->items;
                $eventId   = $checkout->reference;
                $value     = (float) $checkout->grand_total;
                $subject   = $checkout;
            } else {
                $order->loadMissing('items.product');
                $items     = $order->items;
                $eventId   = $order->reference;
                $value     = (float) $order->total_amount;
                $subject   = $order;
            }

            $service = app(MetaConversionsService::class);
            $userData = [
                'email'      => $subject->customer_email,
                'phone'      => $subject->customer_phone,
                'name'       => $subject->customer_name,
                'city'       => $subject->local_government,
                'fbp'        => $subject->fbp,
                'fbc'        => $subject->fbc,
            ];

            $service->dispatchEvent(
                eventName: 'Purchase',
                eventId: $eventId,
                eventSourceUrl: route('checkout'),
                userData: $userData,
                customData: [
                    'currency'     => 'NGN',
                    'value'        => $value,
                    'content_ids'  => $items->pluck('product_id')->all(),
                    // The online names the buyer saw, from the line snapshots.
                    'content_name' => $items->map(fn ($item) => $item->customerName())->unique()->implode(', '),
                    'content_type' => 'product',
                ],
            );

            if ($order->status === 'confirmed' && $order->payment_method === 'pay_on_delivery') {
                $service->dispatchEvent(
                    eventName: 'PaymentConfirmed',
                    eventId: $eventId . '-payment-confirmed',
                    eventSourceUrl: route('checkout'),
                    userData: $userData,
                    customData: [
                        'currency' => 'NGN',
                        'value'    => $value,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error("Meta conversion event dispatch failed for order {$order->id}: " . $e->getMessage());
        }
    }

    // Storekeepers are rarely logged in, so the WhatsApp alert is what actually
    // reaches them. Failures are swallowed: an internal alert must never be the
    // reason a customer's order stops progressing.
    private function notifyStorekeeperOfStatusChange(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        try {
            if (in_array($order->status, StorekeeperNotifier::AWAITING_DISPATCH, true)) {
                app(StorekeeperNotifier::class)->newOrder($order);
            }

            if ($order->status === 'cancelled') {
                app(StorekeeperNotifier::class)->orderCancelled($order);
            }
        } catch (\Throwable $e) {
            Log::warning('Storekeeper notification failed.', [
                'order_id'  => $order->id,
                'status'    => $order->status,
                'exception' => $e->getMessage(),
            ]);
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

            $this->whatsAppVendorOwner($vendor, $order);
        }
    }

    // The owner's own WhatsApp alert, when they have given an owner number.
    // Non-urgent (P2), so three orders in two minutes arrive as one message.
    private function whatsAppVendorOwner(\App\Models\Vendor $vendor, Order $order): void
    {
        try {
            $to = \App\Models\VendorNotificationSetting::forVendor($vendor)->owner_whatsapp;

            if (blank($to)) {
                return;
            }

            $template = MessageTemplate::resolveFor($vendor->id, 'vendor_new_order');

            if (! $template) {
                return;
            }

            $message = DeliveryMessage::create([
                'vendor_id'      => $vendor->id,
                'order_id'       => $order->id,
                'recipient_type' => 'vendor',
                'channel'        => $template->channel,
                'to_number'      => $to,
                'body'           => app(TemplateRenderer::class)->render($template->body, TemplateRenderer::contextForOrder($order, TemplateRenderer::AUDIENCE_STOREKEEPER)),
                'status'         => 'queued',
                'template_key'   => 'vendor_new_order',
            ]);

            app(MessagingService::class)->dispatch($message);
        } catch (\Throwable $e) {
            Log::warning("Vendor new-order WhatsApp failed for order {$order->id}: ".$e->getMessage());
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

        // resolveFor() rather than a direct query so the platform-locked
        // confirmation cannot be switched off by deactivating its template, and
        // still sends for a vendor whose row was never seeded.
        $template = MessageTemplate::resolveFor($vendorId, $templateKey);

        if (! $template || $template->recipient_type !== 'customer') {
            return;
        }

        // The confirmation is one per checkout, not one per vendor part: the
        // first part to settle claims it and lists the whole checkout. It is
        // GadgetPlug's message, so it is filed under no vendor.
        $checkout = $templateKey === 'customer_received' ? $order->checkout : null;

        if ($checkout && ! $checkout->claimCustomerConfirmation()) {
            return;
        }

        $context = $checkout
            ? TemplateRenderer::contextForCheckout($checkout)
            : TemplateRenderer::contextForOrder($order, TemplateRenderer::AUDIENCE_CUSTOMER);

        $message = DeliveryMessage::create([
            'vendor_id'      => $checkout ? null : $vendorId,
            'order_id'       => $order->id,
            'recipient_type' => 'customer',
            'channel'        => $template->channel,
            'to_number'      => $order->customer_phone,
            'body'           => app(TemplateRenderer::class)->render($template->body, $context),
            'status'         => 'queued',
            'template_key'   => $templateKey,
        ]);

        // Queued: this runs inside checkout, which must not wait on a gateway.
        app(MessagingService::class)->dispatch($message);
    }
}
