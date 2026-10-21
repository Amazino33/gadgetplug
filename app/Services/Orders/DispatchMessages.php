<?php

namespace App\Services\Orders;

use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItemStoreAllocation;
use App\Models\Store;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The messages platform dispatch sends, all through the queued MessagingService
 * (the global sender), and the alert to dispatch staff when a rider link locks.
 *
 * Both templates are platform-locked: a vendor cannot switch them off, and a
 * vendor with no stored row gets the shipped default. Both are urgent (P1):
 * the handover message carries the customer's code, the other the rider link.
 */
class DispatchMessages
{
    /** How long a rider link works. */
    public const LINK_HOURS = 48;

    /**
     * The customer's one message at handover: on its way, what to have ready,
     * and the delivery code — the only place the code ever appears in plain
     * text. Also sent alone when dispatch issues a new code.
     */
    public function sendDeliveryCode(Order $order, string $code): void
    {
        $this->queue($order, 'customer_delivery_code', $order->customer_phone, TemplateRenderer::AUDIENCE_CUSTOMER, [
            'delivery_code' => $code,
            'collect_line'  => self::collectLine($order, forCustomer: true),
        ]);
    }

    /**
     * The signed link to the logistics company's dispatch number, and to the
     * rider's own phone when one was entered. Never carries the code.
     */
    public function sendDispatchLink(Order $order): void
    {
        $order->loadMissing('logisticsCompany');

        $extra = [
            'dispatch_link' => self::link($order),
            'pickup'        => self::pickupLine($order),
            'collect_line'  => self::collectLine($order, forCustomer: false),
        ];

        $numbers = collect([$order->logisticsCompany?->dispatchNumber(), $order->rider_phone])
            ->filter()
            ->unique();

        foreach ($numbers as $number) {
            $this->queue($order, 'logistics_dispatch', $number, TemplateRenderer::AUDIENCE_RIDER, $extra);
        }
    }

    /** Five wrong codes: the link is locked and dispatch staff need to act. */
    public function alertCodeLocked(Order $order): void
    {
        foreach (self::dispatchStaff() as $user) {
            Notification::make()
                ->title('Delivery code locked: #'.$order->reference)
                ->body('Five wrong codes were entered on the rider\'s link. Check with the customer and the rider, then send a new code.')
                ->icon('heroicon-o-lock-closed')
                ->danger()
                ->sendToDatabase($user);
        }
    }

    /** The signed rider link: one order, this assignment only, 48 hours. */
    public static function link(Order $order): string
    {
        return URL::temporarySignedRoute(
            'dispatch.show',
            now()->addHours(self::LINK_HOURS),
            ['order' => $order->id, 'v' => (int) $order->dispatch_link_version],
        );
    }

    /** Where to collect: each branch holding this order's stock, with its address and phone. */
    public static function pickupLine(Order $order): string
    {
        $stores = self::pickupStores($order);

        if ($stores->isEmpty()) {
            return $order->items()->first()?->vendor?->name ?? 'The store — call GadgetPlug for the address';
        }

        return $stores->map(fn (Store $store) => trim(implode(', ', array_filter([
            $store->vendor?->name.' — '.$store->name,
            $store->address,
            $store->phone,
        ]))))->implode("\n");
    }

    /** @return \Illuminate\Support\Collection<int, Store> */
    public static function pickupStores(Order $order)
    {
        $storeIds = OrderItemStoreAllocation::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->distinct()
            ->pluck('store_id');

        return Store::with('vendor')->whereIn('id', $storeIds)->get();
    }

    /** What to collect at the door, said for the rider or for the customer. */
    public static function collectLine(Order $order, bool $forCustomer): string
    {
        $amount = $order->amountToCollect();

        if ($amount <= 0) {
            return $forCustomer ? 'Your order is paid — there is nothing to pay at the door.' : '*Payment:* Already paid — collect nothing.';
        }

        $naira = '₦'.number_format($amount, 2);

        return $forCustomer
            ? "Please have *{$naira}* ready to pay on delivery."
            : "*Collect from the customer:* {$naira}";
    }

    private function queue(Order $order, string $key, ?string $to, string $audience, array $extra): void
    {
        try {
            $vendorId = $order->items()->value('vendor_id');

            if (! $vendorId || blank($to)) {
                return;
            }

            $template = MessageTemplate::resolveFor((int) $vendorId, $key);

            if (! $template) {
                return;
            }

            // GadgetPlug's own message: tied to the order, filed under no vendor.
            $message = DeliveryMessage::create([
                'vendor_id'      => null,
                'order_id'       => $order->id,
                'recipient_type' => $template->recipient_type,
                'channel'        => $template->channel,
                'to_number'      => $to,
                'body'           => app(TemplateRenderer::class)->render(
                    $template->body,
                    TemplateRenderer::contextForOrder($order, $audience) + $extra,
                ),
                'status'         => 'queued',
                'template_key'   => $key,
            ]);

            app(MessagingService::class)->dispatch($message);
        } catch (Throwable $e) {
            // A message that fails to queue must not undo the handover or the
            // assignment; it is logged and can be resent from the order.
            Log::warning("Dispatch message {$key} failed for order {$order->id}: ".$e->getMessage());
        }
    }

    /**
     * Who runs dispatch: super-admins, and anyone holding the global
     * dispatcher role or manage_dispatch permission. Read raw, like
     * User::isSuperAdmin(): the roles relation filters by whichever team is
     * current, and this can run anywhere.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function dispatchStaff()
    {
        $morph = (new User)->getMorphClass();

        $byRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $morph)
            ->whereNull('model_has_roles.team_id')
            ->whereIn('roles.name', ['super_admin', 'dispatcher'])
            ->pluck('model_has_roles.model_id');

        $byPermission = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $morph)
            ->whereNull('model_has_permissions.team_id')
            ->where('permissions.name', 'manage_dispatch')
            ->pluck('model_has_permissions.model_id');

        return User::whereIn('id', $byRole->merge($byPermission)->unique())->get();
    }
}
