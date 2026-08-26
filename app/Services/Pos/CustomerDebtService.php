<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Every debt figure in the system is derived here, from the ledger, every time.
 *
 * Same discipline WalletService and PlugPointService already apply: balances are
 * summed from immutable rows, never stored. pos_customers.total_spent is the
 * counter-example living next door — it is incremented at the till, so it can
 * and does drift. Nothing in this service writes anything.
 */
class CustomerDebtService
{
    /**
     * What the customer still owes.
     *
     * A plain SUM, which only works because the model enforces the sign
     * convention on the way in: charges positive, payments and write-offs
     * negative.
     */
    public function outstanding(int $customerId): float
    {
        return round((float) PosCustomerLedgerEntry::where('pos_customer_id', $customerId)->sum('amount'), 2);
    }

    /** Everything ever put on credit — the gross, before anything came back. */
    public function totalCharged(int $customerId): float
    {
        return round((float) PosCustomerLedgerEntry::where('pos_customer_id', $customerId)
            ->charges()
            ->sum('amount'), 2);
    }

    /**
     * Money actually received. Returned positive, because "paid ₦5,000" is what
     * a person means, even though the row is stored negative.
     */
    public function totalPaid(int $customerId): float
    {
        return round(abs((float) PosCustomerLedgerEntry::where('pos_customer_id', $customerId)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_PAYMENT)
            ->sum('amount')), 2);
    }

    /** Written off, positive for the same reason. */
    public function totalWrittenOff(int $customerId): float
    {
        return round(abs((float) PosCustomerLedgerEntry::where('pos_customer_id', $customerId)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_WRITEOFF)
            ->sum('amount')), 2);
    }

    /**
     * The three figures a debt screen shows, in one pass rather than three
     * round trips.
     *
     * @return array{charged: float, paid: float, written_off: float, outstanding: float}
     */
    public function summary(int $customerId): array
    {
        $rows = PosCustomerLedgerEntry::where('pos_customer_id', $customerId)
            ->selectRaw('direction, SUM(amount) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $charged    = round((float) ($rows[PosCustomerLedgerEntry::DIRECTION_CHARGE] ?? 0), 2);
        $paid       = round(abs((float) ($rows[PosCustomerLedgerEntry::DIRECTION_PAYMENT] ?? 0)), 2);
        $writtenOff = round(abs((float) ($rows[PosCustomerLedgerEntry::DIRECTION_WRITEOFF] ?? 0)), 2);

        return [
            'charged'     => $charged,
            'paid'        => $paid,
            'written_off' => $writtenOff,
            'outstanding' => round($charged - $paid - $writtenOff, 2),
        ];
    }

    public function owesAnything(int $customerId): bool
    {
        // Greater than zero, not "not equal to" — an overpayment leaves a
        // negative balance, which is credit owed the other way and emphatically
        // not a debt to chase.
        return $this->outstanding($customerId) > 0;
    }

    /**
     * Outstanding balances for a whole vendor, keyed by customer id, excluding
     * anyone who owes nothing. One query rather than one per customer, since
     * the debt list renders every row at once.
     *
     * @return Collection<int, float>
     */
    public function outstandingByCustomer(int $vendorId): Collection
    {
        return PosCustomerLedgerEntry::forVendor($vendorId)
            ->selectRaw('pos_customer_id, SUM(amount) as balance')
            ->groupBy('pos_customer_id')
            ->havingRaw('SUM(amount) > 0')
            ->pluck('balance', 'pos_customer_id')
            ->map(fn ($balance) => round((float) $balance, 2));
    }

    /** Total a vendor is owed across every customer. */
    public function vendorOutstanding(int $vendorId): float
    {
        return round($this->outstandingByCustomer($vendorId)->sum(), 2);
    }

    /**
     * One customer's history, oldest first, with the running balance after each
     * line — the order a person reads a statement in, and the only way a row's
     * effect is legible on its own.
     *
     * @return Collection<int, array{entry: PosCustomerLedgerEntry, running: float}>
     */
    public function history(int $customerId): Collection
    {
        $running = 0.0;

        return PosCustomerLedgerEntry::where('pos_customer_id', $customerId)
            ->with(['creator', 'store'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(function (PosCustomerLedgerEntry $entry) use (&$running) {
                $running = round($running + (float) $entry->amount, 2);

                return ['entry' => $entry, 'running' => $running];
            });
    }
}
