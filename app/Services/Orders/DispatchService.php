<?php

namespace App\Services\Orders;

use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * GadgetPlug's dispatch work on an online order that is not a status change:
 * which platform logistics company delivers it (and the rider they send),
 * what we pay that company and whether we have, and the customer's delivery
 * code. Status changes stay in OrderStatusService. Every method here is for
 * dispatch staff (OrderPolicy::dispatch).
 */
class DispatchService
{
    /** Statuses at which an order can be assigned or reassigned. */
    public const ASSIGNABLE = ['confirmed', 'paid', 'packed', 'shipped'];

    // ── The delivery code ───────────────────────────────────────────────────

    public static function newDeliveryCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** The columns a fresh code writes: its hash, a clean attempt count, unlocked. */
    public static function codeColumns(string $code): array
    {
        return [
            'delivery_code_hash'      => Hash::make($code),
            'delivery_code_set_at'    => now(),
            'delivery_code_attempts'  => 0,
            'delivery_code_locked_at' => null,
        ];
    }

    public static function codeMatches(Order $order, string $code): bool
    {
        $code = trim($code);

        return preg_match('/^\d{4}$/', $code) === 1
            && $order->delivery_code_hash !== null
            && Hash::check($code, $order->delivery_code_hash);
    }

    /**
     * A new code for an order with the rider — the customer lost theirs, or
     * the link locked after too many wrong tries. Replaces the old code,
     * clears the attempts and the lock, and sends the new code to the customer.
     */
    public function issueNewCode(Order $order, User $actor): void
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if ($order->status !== 'shipped') {
            throw new InvalidOrderTransition('A delivery code is only needed while the order is with the rider.');
        }

        $code = self::newDeliveryCode();

        DB::transaction(function () use ($order, $actor, $code) {
            $order->updateQuietly(self::codeColumns($code));

            activity()->performedOn($order)->causedBy($actor)->event('delivery_code_reissued')
                ->log('New delivery code sent to the customer');

            app(DispatchMessages::class)->sendDeliveryCode($order, $code);
        });
    }

    // ── Assignment ──────────────────────────────────────────────────────────

    /**
     * Give the order to a platform logistics company, optionally naming the
     * rider they send. Reassigning replaces the company and kills the previous
     * rider link (its version moves on). The cost is pre-filled by the caller
     * from expectedCostFor(); once we have paid the company it is locked.
     */
    public function assign(
        Order $order,
        User $actor,
        int $companyId,
        ?string $riderName = null,
        ?string $riderPhone = null,
        ?float $cost = null,
        bool $notify = true,
    ): Order {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if (! in_array($order->status, self::ASSIGNABLE, true)) {
            throw new InvalidOrderTransition('Only an order that has not been delivered, returned or cancelled can be assigned.');
        }

        $company = LogisticsCompany::platform()->where('is_active', true)->find($companyId);

        if (! $company) {
            throw new InvalidOrderTransition('Choose an active GadgetPlug logistics company.');
        }

        if ($cost !== null && $cost < 0) {
            throw new InvalidOrderTransition('The delivery cost cannot be negative.');
        }

        DB::transaction(function () use ($order, $actor, $company, $riderName, $riderPhone, $cost) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['logistics_company_id', 'rider_name', 'rider_phone', 'company_cost']);

            $locked->updateQuietly([
                'logistics_company_id'  => $company->id,
                // Platform orders never use a vendor's own rider records.
                'delivery_person_id'    => null,
                'rider_name'            => filled($riderName) ? trim($riderName) : null,
                'rider_phone'           => filled($riderPhone) ? trim($riderPhone) : null,
                'dispatch_assigned_at'  => now(),
                'dispatch_assigned_by'  => $actor->id,
                'dispatch_link_version' => (int) $locked->dispatch_link_version + 1,
                // What we pay is fixed once paid.
                ...($locked->company_paid_at === null ? ['company_cost' => $cost] : []),
            ]);

            activity()->performedOn($locked)->causedBy($actor)->event('dispatch_assigned')
                ->withProperties(['old' => $before, 'attributes' => $locked->only(array_keys($before))])
                ->log($before['logistics_company_id'] ? 'Reassigned to '.$company->name : 'Assigned to '.$company->name);

            $order->setRawAttributes($locked->getAttributes(), true);
        });

        $order->load('logisticsCompany');

        if ($notify) {
            app(DispatchMessages::class)->sendDispatchLink($order);
        }

        return $order;
    }

    /** Send the current link again (fresh 48 hours), to the company and the rider. */
    public function resendDispatchLink(Order $order, User $actor): void
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if (! $order->logistics_company_id || ! in_array($order->status, self::ASSIGNABLE, true)) {
            throw new InvalidOrderTransition('Assign a logistics company first.');
        }

        app(DispatchMessages::class)->sendDispatchLink($order);
    }

    /**
     * What delivering this order is expected to cost: the destination's
     * current rule, else the figure frozen on the checkout when it was placed.
     */
    public function expectedCostFor(Order $order): ?float
    {
        $checkout = $order->checkout;

        $quote = $checkout?->lga_id ? app(DeliveryPricing::class)->quoteForLgaId($checkout->lga_id) : null;

        if ($quote?->expectedCost !== null) {
            return $quote->expectedCost;
        }

        $snapshot = $checkout?->delivery_rule_snapshot;
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return isset($snapshot['expected_cost']) ? (float) $snapshot['expected_cost'] : null;
    }

    /**
     * Active platform companies, those covering the order's LGA (or its whole
     * state) first, labelled so.
     *
     * @return Collection<int, string> id => label
     */
    public function companyOptionsFor(Order $order): Collection
    {
        $lga = $order->checkout?->lga;

        return LogisticsCompany::platform()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->sortByDesc(fn (LogisticsCompany $company) => $company->covers($lga))
            ->mapWithKeys(fn (LogisticsCompany $company) => [
                $company->id => $company->name.($company->covers($lga) ? ' — covers '.$lga->name : ''),
            ]);
    }

    // ── What we pay the company ─────────────────────────────────────────────

    /** We have paid the company for this delivery. Recorded once; no ledger yet. */
    public function markCompanyPaid(Order $order, User $actor): void
    {
        Gate::forUser($actor)->authorize('dispatch', $order);

        if (! $order->logistics_company_id || $order->company_cost === null) {
            throw new InvalidOrderTransition('Assign a company and its delivery cost before marking it paid.');
        }

        if ($order->company_paid_at !== null) {
            return;
        }

        $order->updateQuietly(['company_paid_at' => now(), 'company_paid_by' => $actor->id]);

        activity()->performedOn($order)->causedBy($actor)->event('company_paid')
            ->withProperties(['amount' => (float) $order->company_cost, 'company_id' => $order->logistics_company_id])
            ->log('Delivery cost marked paid to the logistics company');
    }
}
