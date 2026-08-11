<?php

namespace App\Observers;

use App\Actions\Finance\RecognizeOrderRevenueAction;
use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Models\DeliveryMessage;
use App\Models\FinancialLedgerEntry;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\Affiliate\CommissionService;
use App\Services\FinancialLedger;
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
    private const CUSTOMER_STATUS_TEMPLATES = [
        'paid'      => 'customer_received',
        'confirmed' => 'customer_received',
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
        $this->applyAffiliateCommissionLifecycle($order);
        $this->applyRevenueRecognition($order);
        $this->applyRevenueReversal($order);
        $this->applyMetaConversionEvents($order);
        $this->notifyCustomerOfStatusChange($order);
        $this->notifyStorekeeperOfStatusChange($order);
    }

    // Centralized here — not in ViewOrder's updateStatus alone — because
    // status can also change from the Orders list row action, ListOrders'
    // custom table, and Order Items' own status form (see Prompt 4 recon).
    // Same reasoning as applyStatusTransitionSideEffects below: whichever UI
    // changed the status, this fires. POD needs payment_channel already on
    // the order to post (only ViewOrder's updateStatus captures it today —
    // delivered via one of the other three entry points recognizes nothing
    // and is left for the safety net to surface, never guessed at).
    private function applyRevenueRecognition(Order $order): void
    {
        if (! $order->wasChanged('status') || $order->isRevenueRecognized()) {
            return;
        }

        if (in_array($order->status, ['cancelled', 'paid_but_failed_stock'], true)) {
            return;
        }

        $isPrepaidRecognition = $order->payment_method === 'paystack' && $order->status === 'paid';
        $isPodRecognition     = $order->payment_method === 'pay_on_delivery' && $order->status === 'delivered';

        if (! $isPrepaidRecognition && ! $isPodRecognition) {
            return;
        }

        // Which transitions count is decided here; what recognition *is* lives in
        // the action, shared with the order page's manual recovery. A POD order
        // delivered without a captured channel returns 'no_channel' and stays
        // unrecognized — not an error, just money still waiting to be recorded,
        // which the order page's "Record Payment Received" action clears.
        app(RecognizeOrderRevenueAction::class)->execute($order);
    }

    // Cancelling a previously-recognized order — today this is only reachable
    // for a prepaid order (paid → cancelled): POD's cancellable window is
    // pending/confirmed/paid, all of which are before delivery, the only POD
    // recognition trigger, so a POD order is never both recognized and still
    // cancellable. Kept general rather than paystack-only in case a future
    // status change ever makes a delivered order cancellable too.
    //
    // Never edits/deletes the original 'in' entry — posts a reversing 'out'
    // sourced from that entry itself, not the order. Order already owns the
    // 'out' direction (delivery cost, Prompt 2); sourcing the reversal from
    // the order too would recreate the exact collision the
    // (source_type, source_id, direction) uniqueness fix above exists to
    // prevent. revenue_recognized_at is deliberately left set, not cleared —
    // it remains true that recognition happened at that timestamp; the
    // reversing entry is the correction, not an erasure of history.
    private function applyRevenueReversal(Order $order): void
    {
        if (! $order->wasChanged('status') || $order->status !== 'cancelled' || ! $order->isRevenueRecognized()) {
            return;
        }

        $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)
            ->where('direction', 'in')
            ->first();

        if (! $original) {
            Log::error("Revenue reversal skipped for order {$order->id}: recognized but no original ledger entry found.");

            return;
        }

        try {
            FinancialLedger::postEntry(
                account: $original->account,
                direction: 'out',
                amount: (float) $original->amount,
                source: $original,
                description: "Reversal — order {$order->reference} cancelled after revenue recognition",
                createdBy: auth()->id(),
            );
        } catch (\Throwable $e) {
            Log::error("Revenue reversal failed for order {$order->id}: " . $e->getMessage());
        }
    }

    // Server-side CAPI copy of Purchase — fires on the same "money is settled"
    // transition CUSTOMER_STATUS_TEMPLATES above already uses (confirmed for
    // POD, paid for Paystack), keyed on the order's own reference so Meta
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
            $order->loadMissing('items.product');

            $service = app(MetaConversionsService::class);
            $userData = [
                'email'      => $order->customer_email,
                'phone'      => $order->customer_phone,
                'name'       => $order->customer_name,
                'city'       => $order->local_government,
                'fbp'        => $order->fbp,
                'fbc'        => $order->fbc,
            ];

            $service->dispatchEvent(
                eventName: 'Purchase',
                eventId: $order->reference,
                eventSourceUrl: route('checkout'),
                userData: $userData,
                customData: [
                    'currency'     => 'NGN',
                    'value'        => (float) $order->total_amount,
                    'content_ids'  => $order->items->pluck('product_id')->all(),
                    'content_type' => 'product',
                ],
            );

            if ($order->status === 'confirmed' && $order->payment_method === 'pay_on_delivery') {
                $service->dispatchEvent(
                    eventName: 'PaymentConfirmed',
                    eventId: $order->reference . '-payment-confirmed',
                    eventSourceUrl: route('checkout'),
                    userData: $userData,
                    customData: [
                        'currency' => 'NGN',
                        'value'    => (float) $order->total_amount,
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

    // Delivered starts the commission's hold; cancelled rejects it outright
    // (the only reject-capable transition that exists today — see Prompt 1's
    // Phase 0 recon: 'delivered' is currently terminal in the vendor UI, so a
    // return after delivery has no status to hook here yet). Swallows its own
    // failures rather than letting a bug in commission logic block a vendor
    // from updating order status, matching applyStatusTransitionSideEffects's
    // discipline above.
    private function applyAffiliateCommissionLifecycle(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        try {
            $commissionService = app(CommissionService::class);

            if ($order->status === 'delivered') {
                $commissionService->startReturnWindow($order);
            }

            if ($order->status === 'cancelled') {
                $commissionService->reject($order, 'order_cancelled');
            }
        } catch (\Throwable $e) {
            Log::error("Affiliate commission lifecycle failed for order {$order->id}: " . $e->getMessage());
        }
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
