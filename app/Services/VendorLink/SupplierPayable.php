<?php

declare(strict_types=1);

namespace App\Services\VendorLink;

use App\Models\OrderItem;
use App\Models\SupplierLink;
use App\Models\SupplierPayableEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * What the reseller owes each supplier, and the only way to write it.
 *
 * Balances are always derived — charges less payments over the ledger rows —
 * and never stored, so a balance cannot drift from the history that produced
 * it. Same discipline as the financial, accountability and customer-debt
 * ledgers.
 *
 * Every write is on the reseller's side. The supplier's account is never
 * touched: he is running his shop as before and does not know the online
 * channel exists.
 */
class SupplierPayable
{
    /**
     * Book what is owed for units that reached a customer.
     *
     * Idempotent on the order line. Delivery can fire more than once — a status
     * flipped back and forth, a retried job, two tabs open — and a debt booked
     * twice is money paid twice. The key is the line, not the moment, because
     * the same line delivered again is the same debt.
     *
     * The supplier's price is frozen here and never recomputed. He will change
     * it again; what he charged for these units on this day is what is owed.
     */
    public function charge(
        SupplierLink $link,
        OrderItem $orderItem,
        int $quantity,
        float $unitCost,
    ): SupplierPayableEntry {
        if ($quantity < 1) {
            throw new RuntimeException('A charge has to be for at least one unit.');
        }

        if ($unitCost < 0) {
            throw new RuntimeException('A supplier cost cannot be negative.');
        }

        $key = self::chargeKey($orderItem);

        // Checked first for the ordinary repeat, and the unique index catches
        // the race two concurrent deliveries would otherwise win together.
        $existing = SupplierPayableEntry::where('idempotency_key', $key)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return SupplierPayableEntry::create([
                'vendor_id'        => $link->reseller_vendor_id,
                'supplier_link_id' => $link->id,
                'entry_type'       => SupplierPayableEntry::TYPE_CHARGE,
                'order_item_id'    => $orderItem->id,
                'quantity'         => $quantity,
                'unit_cost'        => round($unitCost, 2),
                'amount'           => round($quantity * $unitCost, 2),
                'idempotency_key'  => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race. The other call's row is the real entry, not an
            // error here.
            return SupplierPayableEntry::where('idempotency_key', $key)->firstOrFail();
        }
    }

    /**
     * Undo a line's charge because its order was cancelled — the units never
     * reached a customer, so nothing is owed for them.
     *
     * Append-only: a 'reversal' row for the charge's full amount, the charge
     * itself untouched. Idempotent on the line, like the charge: cancelling
     * twice reverses once. Null when the line was never charged.
     */
    public function reverseCharge(OrderItem $orderItem, ?User $user = null, ?string $note = null): ?SupplierPayableEntry
    {
        $charge = SupplierPayableEntry::where('idempotency_key', self::chargeKey($orderItem))->first();

        if (! $charge) {
            return null;
        }

        $key = self::reversalKey($orderItem);

        $existing = SupplierPayableEntry::where('idempotency_key', $key)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return SupplierPayableEntry::create([
                'vendor_id'        => $charge->vendor_id,
                'supplier_link_id' => $charge->supplier_link_id,
                'entry_type'       => SupplierPayableEntry::TYPE_REVERSAL,
                'order_item_id'    => $orderItem->id,
                'quantity'         => $charge->quantity,
                'unit_cost'        => $charge->unit_cost,
                'amount'           => $charge->amount,
                'user_id'          => $user?->id,
                'note'             => $note,
                'idempotency_key'  => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return SupplierPayableEntry::where('idempotency_key', $key)->firstOrFail();
        }
    }

    /** Money handed to the supplier against what is owed. */
    public function pay(
        SupplierLink $link,
        float $amount,
        ?User $user = null,
        ?string $note = null,
    ): SupplierPayableEntry {
        if ($amount <= 0) {
            throw new RuntimeException('A payment has to be for some money.');
        }

        return SupplierPayableEntry::create([
            'vendor_id'        => $link->reseller_vendor_id,
            'supplier_link_id' => $link->id,
            'entry_type'       => SupplierPayableEntry::TYPE_PAYMENT,
            'amount'           => round($amount, 2),
            'user_id'          => $user?->id,
            'note'             => $note,
            // Payments have no natural key — the same supplier can genuinely be
            // paid the same amount twice in a day. Left null, which the unique
            // index exempts under standard SQL NULL semantics.
            'idempotency_key'  => null,
        ]);
    }

    /** Still owed on this link: everything charged, less reversals and payments. */
    public function balance(SupplierLink $link): float
    {
        return $this->summary($link)['balance'];
    }

    /**
     * 'charged' is net of reversals — what is owed for goods that actually
     * went — so charged less paid is the balance. 'reversed' is shown apart.
     *
     * @return array{charged: float, reversed: float, paid: float, balance: float}
     */
    public function summary(SupplierLink $link): array
    {
        $row = SupplierPayableEntry::where('supplier_link_id', $link->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = ? THEN amount ELSE 0 END), 0) as charged", [SupplierPayableEntry::TYPE_CHARGE])
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = ? THEN amount ELSE 0 END), 0) as reversed", [SupplierPayableEntry::TYPE_REVERSAL])
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = ? THEN amount ELSE 0 END), 0) as paid", [SupplierPayableEntry::TYPE_PAYMENT])
            ->first();

        $reversed = round((float) $row->reversed, 2);
        $charged  = round((float) $row->charged - $reversed, 2);
        $paid     = round((float) $row->paid, 2);

        return ['charged' => $charged, 'reversed' => $reversed, 'paid' => $paid, 'balance' => round($charged - $paid, 2)];
    }

    /** Every supplier this vendor owes, biggest balance first. */
    public function outstandingByLink(int $vendorId): Collection
    {
        return SupplierLink::forReseller($vendorId)
            ->with('supplier')
            ->get()
            ->map(fn (SupplierLink $link) => [
                'link'          => $link,
                'supplier_name' => $link->supplierName(),
                'balance'       => $this->balance($link),
            ])
            ->filter(fn (array $row) => abs($row['balance']) > 0.009)
            ->sortByDesc('balance')
            ->values();
    }

    /** The key a delivered line books its debt under. */
    public static function chargeKey(OrderItem $orderItem): string
    {
        return 'vendorlink:charge:order_item:'.$orderItem->id;
    }

    /** The key a cancelled line's reversal is booked under. */
    public static function reversalKey(OrderItem $orderItem): string
    {
        return 'vendorlink:reversal:order_item:'.$orderItem->id;
    }
}
