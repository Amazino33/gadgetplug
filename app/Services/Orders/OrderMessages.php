<?php

namespace App\Services\Orders;

use App\Models\DeliveryMessage;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\StorekeeperNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Messages dispatch staff send by hand from the order page.
 *
 * Who can be written to is worked out from the order (the customer, the
 * assigned company or rider, the storekeeper of the branch holding the stock,
 * the vendor's owner), with the same numbers the automated messages use.
 * Sending goes through MessagingService::send(): a message someone pressed a
 * button for skips the digest throttle, as every manual send does, but the
 * WhatsApp brake and the marketing refusal still apply there. Presets are
 * service messages only — nothing promotional.
 */
class OrderMessages
{
    public const TEMPLATE_KEY = 'manual_dispatch';

    public const PRESETS = [
        'delayed'         => 'Order delayed',
        'on_the_way'      => 'Rider on the way',
        'confirm_address' => 'Please confirm your address',
    ];

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly StorekeeperNotifier $storekeepers,
    ) {}

    /**
     * Who this order's message can go to, each with the number it would use.
     * Only recipients with a number are listed.
     *
     * @return array<string, array{label: string, number: string, recipient_type: string}>
     */
    public function recipients(Order $order): array
    {
        $order->loadMissing(['logisticsCompany', 'items.vendor']);
        $vendor = $order->items->first()?->vendor;

        $candidates = [
            'customer' => [
                'label'          => 'Customer — '.$order->customer_name,
                'number'         => $order->customer_phone,
                'recipient_type' => 'customer',
            ],
            'rider' => [
                'label'          => $order->rider_name
                    ? 'Rider — '.$order->rider_name
                    : 'Logistics company — '.($order->logisticsCompany?->name ?? 'not assigned'),
                'number'         => $order->riderContactNumber(),
                'recipient_type' => 'rider',
            ],
            'storekeeper' => [
                'label'          => 'Storekeeper — '.($this->stockStore($order)?->name ?? 'store'),
                'number'         => $this->storekeeperNumber($order),
                'recipient_type' => 'storekeeper',
            ],
            'owner' => [
                'label'          => 'Vendor owner — '.($vendor?->name ?? 'vendor'),
                'number'         => $this->ownerNumber($order),
                'recipient_type' => 'owner',
            ],
        ];

        return array_filter($candidates, fn (array $r) => filled($r['number']));
    }

    /** A preset's wording for this order. */
    public function preset(Order $order, string $key): string
    {
        $name = trim(explode(' ', (string) $order->customer_name)[0]) ?: 'there';
        $ref = $order->reference;

        return match ($key) {
            'delayed'         => "Hello {$name}, this is GadgetPlug about your order #{$ref}. It is running a little late — sorry. We'll update you as soon as it's on its way.",
            'on_the_way'      => "Hello {$name}, your GadgetPlug order #{$ref} is on its way with our rider. Please keep your phone close so they can reach you.",
            'confirm_address' => "Hello {$name}, this is GadgetPlug about order #{$ref}. Please reply with your full delivery address and a landmark nearby, so the rider can find you.",
            default           => throw new InvalidArgumentException("Unknown message preset [{$key}]."),
        };
    }

    /**
     * Send one message now and return its row with the outcome.
     *
     * @throws AuthorizationException without manage_dispatch
     */
    public function send(Order $order, User $sender, string $recipient, string $channel, string $body): DeliveryMessage
    {
        if (! $sender->can('dispatch', $order)) {
            throw new AuthorizationException('Only dispatch staff can message about an order.');
        }

        $target = $this->recipients($order)[$recipient] ?? null;

        if (! $target) {
            throw new InvalidArgumentException('That recipient has no phone number on this order.');
        }

        if (! in_array($channel, ['whatsapp', 'sms'], true)) {
            throw new InvalidArgumentException("Unknown channel [{$channel}].");
        }

        $message = DeliveryMessage::create([
            // GadgetPlug's own message: tied to the order, filed under no vendor.
            'vendor_id'      => null,
            'order_id'       => $order->id,
            'recipient_type' => $target['recipient_type'],
            'channel'        => $channel,
            'to_number'      => $target['number'],
            'body'           => trim($body),
            'status'         => 'queued',
            'template_key'   => self::TEMPLATE_KEY,
            'category'       => DeliveryMessage::CATEGORY_TRANSACTIONAL,
            'sent_by'        => $sender->id,
        ]);

        // send(), not dispatch(): someone is waiting on this one.
        return $this->messaging->send($message);
    }

    /**
     * Every message about this order and the other parts of its checkout,
     * newest first.
     *
     * @return Collection<int, DeliveryMessage>
     */
    public function history(Order $order): Collection
    {
        $orderIds = $order->checkout_id
            ? Order::where('checkout_id', $order->checkout_id)->pluck('id')
            : collect([$order->id]);

        return DeliveryMessage::query()
            ->whereIn('order_id', $orderIds)
            ->with('order:id,reference')
            ->latest('id')
            ->get();
    }

    /** wa.me chat with the customer, for staff to type themselves. */
    public static function whatsappChatUrl(Order $order): ?string
    {
        $number = PhoneNumber::toNigerianInternational((string) $order->customer_phone);
        $digits = preg_replace('/\D/', '', (string) $number);

        return $digits ? 'https://wa.me/'.$digits : null;
    }

    private function stockStore(Order $order)
    {
        return DispatchMessages::pickupStores($order)->first();
    }

    private function storekeeperNumber(Order $order): ?string
    {
        $store = $this->stockStore($order);
        $vendor = $store?->vendor;

        return $store && $vendor ? ($this->storekeepers->storeNumbers($vendor, $store)[0] ?? null) : null;
    }

    private function ownerNumber(Order $order): ?string
    {
        $vendor = $order->items->first()?->vendor;

        if (! $vendor) {
            return null;
        }

        $number = VendorNotificationSetting::forVendor($vendor)->owner_whatsapp
            ?: $vendor->whatsapp
            ?: $vendor->user?->phone;

        return filled($number) ? PhoneNumber::toNigerianInternational($number) : null;
    }
}
