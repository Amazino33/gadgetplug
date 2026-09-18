<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\PlatformMessagingSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Sends the storekeeper's WhatsApp alerts. Storekeepers are usually on the shop
// floor rather than in the admin panel, so WhatsApp — not an in-app notification
// — is the channel that actually reaches them.
//
// Every send funnels through notify(), so the "is this switched on, is there a
// number, is there a template" checks live in exactly one place and each alert
// lands in delivery_messages like any other message.
class StorekeeperNotifier
{
    public function __construct(private MessagingService $messaging) {}

    // Statuses that mean the money is settled but the goods have not left.
    public const AWAITING_DISPATCH = ['paid', 'confirmed'];

    public function newOrder(Order $order): ?DeliveryMessage
    {
        return $this->notifyForOrder($order, 'notify_new_order', 'storekeeper_new_order');
    }

    public function orderCancelled(Order $order): ?DeliveryMessage
    {
        return $this->notifyForOrder($order, 'notify_cancelled', 'storekeeper_cancelled');
    }

    private function notifyForOrder(Order $order, string $toggle, string $templateKey): ?DeliveryMessage
    {
        $vendorId = $order->items()->value('vendor_id');

        if (! $vendorId) {
            return null;
        }

        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return null;
        }

        return $this->notify(
            vendor: $vendor,
            templateKey: $templateKey,
            toggle: $toggle,
            context: TemplateRenderer::contextForOrder($order) + ['store_name' => $vendor->name],
            order: $order,
        );
    }

    // One digest per vendor listing everything still unshipped, rather than one
    // message per order — a store with nine stalled orders should get one nudge,
    // not nine, or the storekeeper starts ignoring them.
    public function undispatchedReminder(Vendor $vendor, ?CarbonInterface $now = null): ?DeliveryMessage
    {
        $now      = $now ? Carbon::instance($now->toDateTime()) : Carbon::now();
        $settings = VendorNotificationSetting::forVendor($vendor);

        $cutoff = $now->copy()->subHours($settings->undispatched_after_hours);

        $orders = Order::query()
            ->whereIn('status', self::AWAITING_DISPATCH)
            ->where('updated_at', '<=', $cutoff)
            // Never chase the pre-activation backlog. Without this, a store that
            // has ever left an order in paid/confirmed would see its entire
            // history listed in the first reminder and every one after it.
            ->when(
                $settings->remind_orders_from,
                fn ($query, $from) => $query->where('created_at', '>=', $from),
            )
            ->whereHas('items', fn ($query) => $query->where('vendor_id', $vendor->id))
            ->orderBy('updated_at')
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $lines = $orders->map(function (Order $order) use ($now): string {
            $waited = $order->updated_at->diffForHumans($now, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);

            return '• '.$order->reference.' — '.$order->customer_name
                .' ('.$waited.' waiting)';
        })->implode("\n");

        $oldestWait = $orders->first()->updated_at
            ->diffForHumans($now, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);

        return $this->notify(
            vendor: $vendor,
            templateKey: 'storekeeper_undispatched',
            toggle: 'notify_undispatched',
            context: [
                'store_name'  => $vendor->name,
                'order_count' => (string) $orders->count(),
                'order_list'  => $lines,
                'oldest_wait' => $oldestWait,
            ],
            // Digests cover several orders, so the log row is deliberately not
            // tied to any single one.
            order: null,
        );
    }

    public function lowStockAlert(Vendor $vendor): ?DeliveryMessage
    {
        $products = Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $lines = $products->map(
            fn (Product $product): string => '• '.$product->name.' — '.$product->stock_quantity.' left'
        )->implode("\n");

        return $this->notify(
            vendor: $vendor,
            templateKey: 'storekeeper_low_stock',
            toggle: 'notify_low_stock',
            context: [
                'store_name'    => $vendor->name,
                'product_count' => (string) $products->count(),
                'product_list'  => $lines,
            ],
            order: null,
        );
    }

    /**
     * A branch has been holding takings for longer than it should have.
     *
     * Deliberately sent before the money becomes a shortage rather than after:
     * the threshold is the same grace window the settlement uses, so this
     * arrives at the moment unremitted cash would start being counted against
     * somebody, and usually prevents that instead of reporting it.
     *
     * Addressed to the storekeeper holding it, not the owner. Nearly every
     * instance is somebody who has not got round to it, and a question to them
     * settles it; escalating first would turn a reminder into an accusation.
     */
    public function unremittedCashAlert(Vendor $vendor, Store $store, float $amount, int $days): ?DeliveryMessage
    {
        return $this->notify(
            vendor: $vendor,
            templateKey: 'storekeeper_unremitted_cash',
            toggle: 'notify_unremitted_cash',
            context: [
                'store_name' => $store->name,
                'amount'     => number_format($amount, 2),
                'days'       => (string) $days,
            ],
            order: null,
        );
    }

    /**
     * @param  array<string, string>  $context
     */
    private function notify(
        Vendor $vendor,
        string $templateKey,
        string $toggle,
        array $context,
        ?Order $order,
    ): ?DeliveryMessage {
        $settings = VendorNotificationSetting::forVendor($vendor);
        $locked   = MessageTemplate::isPlatformLocked($templateKey);

        // A locked alert ignores the vendor's toggle. While GadgetPlug is
        // handling online orders, "was this order announced to the store?" must
        // not depend on a switch the vendor can flip.
        if (! $locked && ! $settings->{$toggle}) {
            return null;
        }

        $to = $this->recipientFor($settings, $locked);

        if (blank($to)) {
            // Only reachable for a locked alert with no vendor number AND no
            // platform fallback — a configuration hole rather than a choice, so
            // it is logged instead of passing silently like an opted-out alert.
            if ($locked) {
                Log::warning('Storekeeper alert had nowhere to go — no vendor number and no platform fallback.', [
                    'vendor_id' => $vendor->id,
                    'template'  => $templateKey,
                    'order_id'  => $order?->id,
                ]);
            }

            return null;
        }

        $template = MessageTemplate::resolveFor($vendor->id, $templateKey);

        if (! $template) {
            return null;
        }

        $message = DeliveryMessage::create([
            'vendor_id'      => $vendor->id,
            'order_id'       => $order?->id,
            'recipient_type' => 'storekeeper',
            'channel'        => $template->channel,
            'to_number'      => $to,
            'body'           => app(TemplateRenderer::class)->render($template->body, $context),
            'status'         => 'queued',
        ]);

        return $this->messaging->send($message);
    }

    // The vendor's own storekeeper when they have set one, otherwise GadgetPlug's
    // fallback number. Only locked alerts fall back: an alert a vendor has opted
    // into is theirs to receive, and routing it to the platform instead would
    // send their order traffic somewhere they never agreed to.
    private function recipientFor(VendorNotificationSetting $settings, bool $locked): ?string
    {
        if ($settings->hasStorekeeperNumber()) {
            return $settings->storekeeper_whatsapp;
        }

        if (! $locked) {
            return null;
        }

        return PlatformMessagingSetting::current()->fallback_storekeeper_whatsapp;
    }
}
