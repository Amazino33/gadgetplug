<?php

namespace App\Services\Orders;

use App\Actions\Finance\RecognizeOrderRevenueAction;
use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\VendorLink\BookSupplierPayableAction;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Affiliate\CommissionService;
use App\Services\FinancialLedger;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way an online order's status changes.
 *
 * Owns which transitions are allowed, who may make each (OrderPolicy), and what
 * each one does to stock and money — so the same move has the same effect from
 * every screen, and nothing else can change it on the side. OrderObserver only
 * sends the messages and tracking events that follow a change.
 *
 *  status                 → reached by            who                 does
 *  ─────────────────────────────────────────────────────────────────────────────
 *  confirmed              confirm()               system (POD checkout)  —
 *  paid                   markPaid()              system (Paystack)      revenue → Due from GadgetPlug; supplier payable
 *  paid_but_failed_stock  markPaidButFailedStock()system (Paystack)      —
 *  packed                 pack()                  store (fulfil)         —
 *  shipped (handed over)  handOver()              store (fulfil)         stock leaves the allocated store(s), COGS
 *  delivered              markDelivered()         GadgetPlug (dispatch)  logistics only; affiliate return window starts
 *  cancelled              cancel()                GadgetPlug (dispatch)  release holds; reverse revenue; reject commission
 *  (no status change)     recordPaymentReceived() GadgetPlug (dispatch)  POD revenue → Due from GadgetPlug; supplier payable
 *
 * "Delivered" moves no stock and no money: stock leaves at the handover (the
 * store's sale), money is booked when it is actually collected.
 */
class OrderStatusService
{
    /** @var array<string, array<int, string>> target status => statuses it may come from */
    public const TRANSITIONS = [
        'confirmed'             => ['pending'],
        'paid'                  => ['pending'],
        'paid_but_failed_stock' => ['pending'],
        'packed'                => ['confirmed', 'paid'],
        'shipped'               => ['confirmed', 'paid', 'packed'],
        'delivered'             => ['shipped'],
        'cancelled'             => ['pending', 'confirmed', 'paid', 'packed', 'paid_but_failed_stock'],
    ];

    public const STATUSES = ['pending', 'confirmed', 'paid', 'paid_but_failed_stock', 'packed', 'shipped', 'delivered', 'cancelled'];

    /** Staff-facing names. Customers see their own wording on /track. */
    public const LABELS = [
        'pending'               => 'Pending payment',
        'confirmed'             => 'Confirmed',
        'paid'                  => 'Paid',
        'paid_but_failed_stock' => 'Stock issue',
        'packed'                => 'Packed',
        'shipped'               => 'Handed to rider',
        'delivered'             => 'Delivered',
        'cancelled'             => 'Cancelled',
    ];

    public static function canMove(Order $order, string $to): bool
    {
        return in_array($order->status, self::TRANSITIONS[$to] ?? [], true);
    }

    /**
     * The steps a store can take from where the order is now — what every
     * vendor-panel status picker offers. Never delivered or cancelled.
     *
     * @return array<string, string>
     */
    public static function storeOptionsFor(Order $order): array
    {
        return collect(['packed', 'shipped'])
            ->filter(fn (string $to) => self::canMove($order, $to))
            ->mapWithKeys(fn (string $to) => [$to => self::LABELS[$to]])
            ->all();
    }

    /** Runs a store step chosen from storeOptionsFor(); anything else is refused. */
    public static function applyStoreStep(Order $order, User $actor, string $to, bool $notifyCustomer = true): Order
    {
        return match ($to) {
            'packed'  => app(self::class)->pack($order, $actor),
            'shipped' => app(self::class)->handOver($order, $actor, $notifyCustomer),
            default   => throw new InvalidOrderTransition('The store can only mark an order packed or handed to the rider.'),
        };
    }

    // ── System transitions (checkout and payment) ───────────────────────────

    /** Pay-on-delivery order placed: its stock is held and it is ready to pack. */
    public function confirm(Order $order): Order
    {
        return $this->move($order, 'confirmed', null, []);
    }

    /** Paystack money confirmed: revenue is booked now, into Due from GadgetPlug. */
    public function markPaid(Order $order): Order
    {
        return $this->move($order, 'paid', null, [], function (Order $order) {
            $this->recogniseRevenue($order);
        });
    }

    /** Paid, but a unit could not be held: flagged for a human, nothing booked. */
    public function markPaidButFailedStock(Order $order): Order
    {
        return $this->move($order, 'paid_but_failed_stock', null, []);
    }

    // ── The store's part ────────────────────────────────────────────────────

    public function pack(Order $order, User $actor): Order
    {
        Gate::forUser($actor)->authorize('fulfil', $order);

        return $this->move($order, 'packed', $actor, ['packed_at' => now(), 'packed_by' => $actor->id]);
    }

    /**
     * The store hands the goods to the rider: this is the store's sale. Stock
     * leaves the store(s) that held it and the cost of goods books, exactly as
     * dispatch always did. The store must not also ring these goods on the till.
     */
    public function handOver(Order $order, User $actor, bool $notifyCustomer = true): Order
    {
        Gate::forUser($actor)->authorize('fulfil', $order);

        return $this->move(
            $order,
            'shipped',
            $actor,
            ['handed_over_at' => now(), 'handed_over_by' => $actor->id],
            function (Order $order) use ($actor) {
                $order->load('items.product');

                foreach ($order->items as $item) {
                    // A resold line's goods are the supplier's: nothing of ours moves.
                    if (! $item->tracksStock()) {
                        continue;
                    }

                    try {
                        app(DispatchStockAction::class)->execute(
                            productId:   $item->product_id,
                            quantity:    $item->quantity,
                            userId:      $actor->id,
                            reference:   $order->reference,
                            description: 'Handed to rider — online order.',
                            orderItemId: $item->id,
                        );
                    } catch (Throwable $e) {
                        // The goods have left either way; a bookkeeping gap is
                        // surfaced in the log rather than blocking the handover.
                        Log::error("Handover stock movement failed for order {$order->id}: ".$e->getMessage());
                    }
                }
            },
            notifyCustomer: $notifyCustomer,
        );
    }

    // ── GadgetPlug's part ───────────────────────────────────────────────────

    /**
     * The customer has the goods. A logistics record only — no stock, revenue,
     * cost, supplier payable or ledger moves. Until the rider's delivery-code
     * page exists, only an admin can record it, and must say why.
     */
    public function markDelivered(Order $order, User $actor, string $reason, bool $notifyCustomer = true): Order
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidOrderTransition('Say why this order is being marked delivered.');
        }

        return $this->move(
            $order,
            'delivered',
            $actor,
            ['delivered_at' => now(), 'delivered_by' => $actor->id, 'delivered_override_reason' => $reason],
            function (Order $order) {
                // A commission hold, not a book entry: the return window starts
                // when the customer has the item.
                try {
                    app(CommissionService::class)->startReturnWindow($order);
                } catch (Throwable $e) {
                    Log::error("Affiliate return window failed for order {$order->id}: ".$e->getMessage());
                }
            },
            notifyCustomer: $notifyCustomer,
        );
    }

    /**
     * Pay-on-delivery money is in GadgetPlug's hands: revenue and the supplier
     * payable book now, on this vendor order only, into Due from GadgetPlug.
     * Changes no status.
     */
    public function recordPaymentReceived(Order $order, User $actor, string $channel): ?string
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if ($order->payment_method !== 'pay_on_delivery') {
            throw new InvalidOrderTransition('Only a pay-on-delivery order has a payment to record.');
        }

        if (! in_array($order->status, ['shipped', 'delivered'], true)) {
            throw new InvalidOrderTransition('Record the payment once the order has been handed to the rider.');
        }

        if (! in_array($channel, ['cash', 'bank_transfer'], true)) {
            throw new InvalidOrderTransition('Choose cash or bank transfer.');
        }

        return DB::transaction(function () use ($order, $actor, $channel) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->isRevenueRecognized()) {
                return 'already_recognized';
            }

            $locked->updateQuietly([
                'payment_collected_at' => now(),
                'payment_collected_by' => $actor->id,
                'payment_channel'      => $channel,
            ]);

            $reason = $this->recogniseRevenue($locked, $channel);

            activity()->performedOn($locked)->causedBy($actor)->event('payment_received')
                ->withProperties(['channel' => $channel, 'amount' => (float) $locked->total_amount, 'result' => $reason ?? 'recognised'])
                ->log('Payment received recorded');

            $order->refresh();

            return $reason;
        });
    }

    public function cancel(Order $order, User $actor, string $reason): Order
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidOrderTransition('Say why this order is being cancelled.');
        }

        return $this->move(
            $order,
            'cancelled',
            $actor,
            ['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason],
            function (Order $order) use ($actor) {
                $order->load('items.product');

                foreach ($order->items as $item) {
                    if (! $item->tracksStock()) {
                        continue;
                    }

                    try {
                        app(ReleaseReservationAction::class)->execute(
                            productId:   $item->product_id,
                            quantity:    $item->quantity,
                            userId:      $actor->id,
                            reference:   $order->reference,
                            description: 'Reservation released — order cancelled.',
                            orderItemId: $item->id,
                        );
                    } catch (Throwable $e) {
                        Log::error("Release reservation failed for order {$order->id}: ".$e->getMessage());
                    }
                }

                $this->reverseRevenue($order, $actor);

                try {
                    app(CommissionService::class)->reject($order, 'order_cancelled');
                } catch (Throwable $e) {
                    Log::error("Affiliate commission reject failed for order {$order->id}: ".$e->getMessage());
                }
            },
        );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Lock, check the move is allowed from where the order is now, write the
     * status and timeline, then run the side effects — all in one transaction,
     * so a refused or failed move leaves nothing half-done.
     */
    private function move(
        Order $order,
        string $to,
        ?User $actor,
        array $timeline,
        ?Closure $effects = null,
        bool $notifyCustomer = true,
    ): Order {
        return DB::transaction(function () use ($order, $to, $timeline, $effects, $notifyCustomer) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! self::canMove($locked, $to)) {
                throw new InvalidOrderTransition(sprintf(
                    'This order is %s and cannot be moved to %s.',
                    strtolower(self::LABELS[$locked->status] ?? $locked->status),
                    strtolower(self::LABELS[$to] ?? $to),
                ));
            }

            // Read by OrderObserver: the "notify silently" toggle on the panel.
            $locked->skipCustomerNotification = ! $notifyCustomer;
            $locked->update(['status' => $to] + $timeline);

            if ($effects) {
                $effects($locked);
            }

            $order->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /** Revenue into Due from GadgetPlug, then the supplier payable at the same moment. */
    private function recogniseRevenue(Order $order, ?string $channel = null): ?string
    {
        $reason = app(RecognizeOrderRevenueAction::class)->execute($order, $channel);

        if ($reason !== null && $reason !== 'already_recognized') {
            Log::warning("Revenue not recognised for order {$order->id}: {$reason}");
        }

        try {
            app(BookSupplierPayableAction::class)->execute($order);
        } catch (Throwable $e) {
            Log::error("Supplier payable failed for order {$order->id}: ".$e->getMessage());
        }

        return $reason;
    }

    /**
     * Reverse recognised revenue on cancellation: a reversing 'out' from the
     * same account the revenue went into (Due from GadgetPlug now, a vendor's
     * cash or bank for revenue booked before that rule). The original entry is
     * never edited; revenue_recognized_at stays as the record it happened.
     */
    private function reverseRevenue(Order $order, User $actor): void
    {
        if (! $order->isRevenueRecognized()) {
            return;
        }

        $original = FinancialLedgerEntry::where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)
            ->where('direction', 'in')
            ->first();

        if (! $original) {
            Log::error("Revenue reversal skipped for order {$order->id}: recognised but no original ledger entry found.");

            return;
        }

        try {
            FinancialLedger::postEntry(
                account: $original->account,
                direction: 'out',
                amount: (float) $original->amount,
                source: $original,
                description: "Reversal — order {$order->reference} cancelled after revenue recognition",
                createdBy: $actor->id,
            );
        } catch (Throwable $e) {
            Log::error("Revenue reversal failed for order {$order->id}: ".$e->getMessage());
        }
    }
}
