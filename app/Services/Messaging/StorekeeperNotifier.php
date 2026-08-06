<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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

        if (! $settings->{$toggle} || ! $settings->hasStorekeeperNumber()) {
            return null;
        }

        $template = MessageTemplate::query()
            ->where('vendor_id', $vendor->id)
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return null;
        }

        $message = DeliveryMessage::create([
            'vendor_id'      => $vendor->id,
            'order_id'       => $order?->id,
            'recipient_type' => 'storekeeper',
            'channel'        => $template->channel,
            'to_number'      => $settings->storekeeper_whatsapp,
            'body'           => app(TemplateRenderer::class)->render($template->body, $context),
            'status'         => 'queued',
        ]);

        return $this->messaging->send($message);
    }
}
