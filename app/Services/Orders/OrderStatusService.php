<?php

namespace App\Services\Orders;

use App\Actions\Finance\RecognizeOrderRevenueAction;
use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\VendorLink\BookSupplierPayableAction;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\CommissionService;
use App\Services\FinancialLedger;
use App\Services\VendorLink\SupplierPayable;
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
 *  (created confirmed)    recordWalletPayment()   system (wallet debit)  revenue → Due from GadgetPlug; supplier payable
 *  packed                 pack()                  store (fulfil)         —
 *  shipped (handed over)  handOver()              store (fulfil)         stock leaves the allocated store(s), COGS; customer gets a delivery code
 *  delivered              confirmDeliveryWithCode() rider (signed link + code)  logistics only; affiliate return window starts
 *  delivered              markDelivered()         GadgetPlug (dispatch, override with reason)  the same
 *  returned               returnToStore()         GadgetPlug (dispatch)  failed delivery: stock back to the branch it left, at its cost; no revenue
 *  cancelled              cancel()                GadgetPlug (dispatch)  release holds; reverse revenue and supplier payable; refund a wallet; reject commission
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
        'returned'              => ['shipped'],
        // A returned order's goods are already back on the shelf: cancelling it
        // settles the money (revenue reversal, wallet refund) and moves no stock.
        'cancelled'             => ['pending', 'confirmed', 'paid', 'packed', 'paid_but_failed_stock', 'returned'],
    ];

    public const STATUSES = ['pending', 'confirmed', 'paid', 'paid_but_failed_stock', 'packed', 'shipped', 'delivered', 'returned', 'cancelled'];

    /** Staff-facing names. Customers see their own wording on /track. */
    public const LABELS = [
        'pending'               => 'Pending payment',
        'confirmed'             => 'Confirmed',
        'paid'                  => 'Paid',
        'paid_but_failed_stock' => 'Stock issue',
        'packed'                => 'Packed',
        'shipped'               => 'Handed to rider',
        'delivered'             => 'Delivered',
        'returned'              => 'Returned to store',
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

    /**
     * A reseller paid from their GadgetPlug wallet: the money is GadgetPlug's
     * to pass on, exactly like a Paystack payment. Revenue (items only) goes to
     * the selling vendor's Due from GadgetPlug account and the supplier payable
     * books with it. The order is created already confirmed, so this changes
     * no status; it is called at the moment of the wallet debit.
     */
    public function recordWalletPayment(Order $order): ?string
    {
        if ($order->payment_method !== 'wallet') {
            throw new InvalidOrderTransition('Only a wallet purchase is paid from the wallet.');
        }

        return $this->recogniseRevenue($order);
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

        // The customer's delivery code: four digits, stored only as a hash.
        // The rider must enter it to mark the order delivered, so it reaches
        // the customer, never the rider page or /track.
        $code = DispatchService::newDeliveryCode();

        return $this->move(
            $order,
            'shipped',
            $actor,
            ['handed_over_at' => now(), 'handed_over_by' => $actor->id] + DispatchService::codeColumns($code),
            function (Order $order) use ($actor, $code) {
                app(DispatchMessages::class)->sendDeliveryCode($order, $code);

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
     * The customer has the goods, recorded by GadgetPlug without the delivery
     * code — an override, so a reason is required and logged. A logistics
     * record only — no stock, revenue, cost, supplier payable or ledger moves.
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
            ['delivered_at' => now(), 'delivered_by' => $actor->id, 'delivered_override_reason' => $reason, 'delivered_via' => 'override'],
            fn (Order $order) => $this->startReturnWindow($order),
            notifyCustomer: $notifyCustomer,
        );
    }

    /**
     * The rider marks the order delivered on the signed dispatch page, with
     * the customer's code. The link is the authority (whoever holds it was
     * sent it); the code proves the customer has the goods.
     *
     * A wrong code counts against the order; at the fifth the link locks and
     * GadgetPlug is alerted, and only a new code (admin) unlocks it.
     *
     * @return array{result: string, remaining?: int}
     *   result: delivered | wrong_code | locked | already_delivered | not_with_rider
     */
    public function confirmDeliveryWithCode(Order $order, string $code): array
    {
        return DB::transaction(function () use ($order, $code) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'delivered') {
                return ['result' => 'already_delivered'];
            }

            if ($locked->status !== 'shipped' || $locked->delivery_code_hash === null) {
                return ['result' => 'not_with_rider'];
            }

            if ($locked->isDeliveryCodeLocked()) {
                return ['result' => 'locked'];
            }

            if (! DispatchService::codeMatches($locked, $code)) {
                $attempts = (int) $locked->delivery_code_attempts + 1;
                $lockNow = $attempts >= Order::DELIVERY_CODE_MAX_ATTEMPTS;

                $locked->updateQuietly([
                    'delivery_code_attempts'  => $attempts,
                    'delivery_code_locked_at' => $lockNow ? now() : null,
                ]);

                activity()->performedOn($locked)->event('delivery_code_wrong')
                    ->withProperties(['attempts' => $attempts, 'locked' => $lockNow])
                    ->log($lockNow ? 'Delivery code locked after too many wrong attempts' : 'Wrong delivery code entered');

                if ($lockNow) {
                    app(DispatchMessages::class)->alertCodeLocked($locked);

                    return ['result' => 'locked'];
                }

                return ['result' => 'wrong_code', 'remaining' => Order::DELIVERY_CODE_MAX_ATTEMPTS - $attempts];
            }

            $this->move(
                $locked,
                'delivered',
                null,
                ['delivered_at' => now(), 'delivered_by' => null, 'delivered_via' => 'code'],
                fn (Order $order) => $this->startReturnWindow($order),
            );

            $order->refresh();

            return ['result' => 'delivered'];
        });
    }

    /**
     * Failed delivery: the goods come back to the store. Each unit returns to
     * the branch it was handed over from, as an append-only stock movement,
     * at the cost it left with (so the cost layer is restored, not revalued).
     * No revenue moves; the order stays as it was paid for until GadgetPlug
     * cancels it (which then settles the money) — and the rider's link and
     * code stop working.
     */
    public function returnToStore(Order $order, User $actor, string $reason): Order
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidOrderTransition('Say why the delivery failed.');
        }

        return $this->move(
            $order,
            'returned',
            $actor,
            [
                'returned_at'           => now(),
                'returned_by'           => $actor->id,
                'return_reason'         => $reason,
                'delivery_code_hash'    => null,
                'dispatch_link_version' => (int) $order->dispatch_link_version + 1,
            ],
            function (Order $order) use ($actor) {
                $order->load('items.product');

                foreach ($order->items as $item) {
                    if (! $item->tracksStock()) {
                        continue;
                    }

                    try {
                        $this->returnLineToStore($order, $item, $actor);
                    } catch (Throwable $e) {
                        Log::error("Return to store failed for order item {$item->id}: ".$e->getMessage());
                    }
                }
            },
        );
    }

    /**
     * One line's units back to the branches they left, read from its own
     * 'dispatched' movements — never re-derived — at the cost each carried.
     */
    private function returnLineToStore(Order $order, \App\Models\OrderItem $item, User $actor): void
    {
        $dispatched = \App\Models\InventoryLedger::query()
            ->where('product_id', $item->product_id)
            ->where('reference', $order->reference)
            ->where('transaction_type', 'dispatched')
            ->get(['store_id', 'quantity_change', 'cost_total']);

        foreach ($dispatched->groupBy('store_id') as $storeId => $rows) {
            $units = (int) abs($rows->sum('quantity_change'));

            if ($units < 1) {
                continue;
            }

            $cost = $rows->whereNotNull('cost_total')->isNotEmpty()
                ? round((float) $rows->sum('cost_total') / $units, 2)
                : null;

            app(\App\Actions\Inventory\AdjustStockAction::class)->execute(
                productId: $item->product_id,
                quantityChanged: $units,
                transactionType: 'online_return',
                userId: $actor->id,
                reference: $order->reference,
                description: 'Returned to store — failed delivery: '.$order->return_reason,
                store: (int) $storeId,
                unitCost: $cost,
            );
        }
    }

    /** A commission hold, not a book entry: the return window starts when the customer has the item. */
    private function startReturnWindow(Order $order): void
    {
        try {
            app(CommissionService::class)->startReturnWindow($order);
        } catch (Throwable $e) {
            Log::error("Affiliate return window failed for order {$order->id}: ".$e->getMessage());
        }
    }

    /**
     * Pay-on-delivery money is in GadgetPlug's hands: revenue and the supplier
     * payable book now, on this vendor order only, into Due from GadgetPlug.
     * Changes no status.
     *
     * $collectedByVendor is for orders placed before the cut-over only: the
     * vendor's own rider took the cash, so it books to the vendor's own cash
     * account as the old rule did (channel cash). Refused on any later order.
     */
    public function recordPaymentReceived(Order $order, User $actor, string $channel, bool $collectedByVendor = false): ?string
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if ($order->payment_method !== 'pay_on_delivery') {
            throw new InvalidOrderTransition('Only a pay-on-delivery order has a payment to record.');
        }

        if (! in_array($order->status, ['shipped', 'delivered'], true)) {
            throw new InvalidOrderTransition('Record the payment once the order has been handed to the rider.');
        }

        if ($collectedByVendor) {
            if (! $order->predatesStatusCutover()) {
                throw new InvalidOrderTransition('Only an order placed before the switch to Due from GadgetPlug can be recorded as collected by the vendor.');
            }

            $channel = 'cash';
        }

        if (! in_array($channel, ['cash', 'bank_transfer'], true)) {
            throw new InvalidOrderTransition('Choose cash or bank transfer.');
        }

        return DB::transaction(function () use ($order, $actor, $channel, $collectedByVendor) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->isRevenueRecognized()) {
                return 'already_recognized';
            }

            $locked->updateQuietly([
                'payment_collected_at' => now(),
                'payment_collected_by' => $actor->id,
                'payment_channel'      => $channel,
            ]);

            $reason = $this->recogniseRevenue($locked, $channel, $collectedByVendor);

            activity()->performedOn($locked)->causedBy($actor)->event('payment_received')
                ->withProperties([
                    'channel'      => $channel,
                    'collected_by' => $collectedByVendor ? 'vendor_legacy' : 'gadgetplug',
                    'amount'       => (float) $locked->total_amount,
                    'result'       => $reason ?? 'recognised',
                ])
                ->log($collectedByVendor ? 'Payment received recorded — collected by vendor (legacy)' : 'Payment received recorded');

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
                    // Nothing held for a resold line; nothing held any more for
                    // a returned order — its goods are already back on the shelf.
                    if (! $item->tracksStock() || $order->returned_at !== null) {
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
                $this->reverseSupplierPayable($order, $actor);
                $this->refundWallet($order);

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
    private function recogniseRevenue(Order $order, ?string $channel = null, bool $collectedByVendor = false): ?string
    {
        $reason = app(RecognizeOrderRevenueAction::class)->execute($order, $channel, $collectedByVendor);

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
     * Give a reseller's wallet back what this order took from it. One child
     * order of a checkout has one debit of its own, so a mixed checkout gets
     * back only the part that was cancelled.
     *
     * Append-only: a credit row referencing the order, for exactly the debit's
     * amount. Booked once — cancel() holds this order's row lock and a status
     * can only be cancelled once, and an existing refund is checked for too.
     * Unlike the bookkeeping reversals this is the customer's money, so a
     * failure here fails the cancellation rather than being logged and passed.
     */
    private function refundWallet(Order $order): void
    {
        if ($order->payment_method !== 'wallet') {
            return;
        }

        $debit = WalletTransaction::where('order_id', $order->id)->where('type', 'debit')->first();

        if (! $debit || WalletTransaction::where('order_id', $order->id)->where('type', 'credit')->exists()) {
            return;
        }

        WalletTransaction::create([
            'affiliate_id' => $debit->affiliate_id,
            'order_id'     => $order->id,
            'type'         => 'credit',
            'amount'       => abs((float) $debit->amount),
            'description'  => "Refund — order #{$order->reference} cancelled.",
        ]);
    }

    /**
     * Undo what the supplier was owed for this order's resold lines: the goods
     * never went, so nothing is owed. Append-only reversal per charged line;
     * a line never charged (a pay-on-delivery order cancelled before its
     * payment) has nothing to reverse.
     */
    private function reverseSupplierPayable(Order $order, User $actor): void
    {
        foreach ($order->items as $item) {
            try {
                app(SupplierPayable::class)->reverseCharge($item, $actor, "Order {$order->reference} cancelled");
            } catch (Throwable $e) {
                Log::error("Supplier payable reversal failed for order item {$item->id}: ".$e->getMessage());
            }
        }
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
