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
     * How old the money owed actually is, oldest charge first.
     *
     * Payments are not matched to a charge anywhere — the ledger stores signed
     * rows and the balance is their sum — so the matching is done here, at read
     * time, by applying every payment to the oldest unsettled charge first.
     *
     * Derived rather than stored, for the same reason every other balance in
     * this service is: the rows are immutable and the order is total, so the
     * same allocation falls out identically every time it is asked for, and a
     * stored copy could only ever drift from them.
     *
     * Scoped to a branch by where the charge was rung, not where the payment
     * was taken — a customer settling up at head office does not move the debt
     * they ran up at a branch, it clears it.
     *
     * @return array{total: float, buckets: array<string, float>, debtors: Collection}
     */
    public function aged(int $vendorId, ?int $storeId = null, ?\DateTimeInterface $asOf = null): array
    {
        $asOf = $asOf ? \Illuminate\Support\Carbon::parse($asOf) : now();

        $entries = PosCustomerLedgerEntry::forVendor($vendorId)
            ->where('occurred_at', '<=', $asOf)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('pos_customer_id');

        $buckets = ['current' => 0.0, '8_30' => 0.0, '31_60' => 0.0, 'over_60' => 0.0];
        $debtors = collect();

        foreach ($entries as $customerId => $rows) {
            $open = $this->applyPaymentsOldestFirst($rows);

            if ($storeId !== null) {
                $open = $open->where('store_id', $storeId);
            }

            $owed = round((float) $open->sum('amount'), 2);

            if ($owed <= 0.009) {
                continue;
            }

            $oldest = $open->min('occurred_at');

            foreach ($open as $slice) {
                $days = (int) \Illuminate\Support\Carbon::parse($slice['occurred_at'])->diffInDays($asOf);
                $key = match (true) {
                    $days <= 7  => 'current',
                    $days <= 30 => '8_30',
                    $days <= 60 => '31_60',
                    default     => 'over_60',
                };

                $buckets[$key] = round($buckets[$key] + $slice['amount'], 2);
            }

            $debtors->push([
                'customer_id'  => (int) $customerId,
                'outstanding'  => $owed,
                'oldest_at'    => $oldest,
                'days_oldest'  => $oldest ? (int) \Illuminate\Support\Carbon::parse($oldest)->diffInDays($asOf) : 0,
            ]);
        }

        return [
            'total'   => round($debtors->sum('outstanding'), 2),
            'buckets' => $buckets,
            'debtors' => $debtors->sortByDesc('days_oldest')->values(),
        ];
    }

    /**
     * Draw every payment and write-off down against the oldest charges.
     *
     * @return Collection<int, array{amount: float, occurred_at: mixed, store_id: ?int}>
     */
    private function applyPaymentsOldestFirst(Collection $rows): Collection
    {
        $open = collect();
        $credit = 0.0;

        foreach ($rows as $row) {
            $amount = (float) $row->amount;

            if ($amount > 0) {
                $open->push([
                    'amount'      => $amount,
                    'occurred_at' => $row->occurred_at,
                    'store_id'    => $row->store_id,
                ]);

                continue;
            }

            // Payments and write-offs both reduce what is owed; neither is tied
            // to a charge, so both land on the oldest one still open.
            $credit += abs($amount);
        }

        return $open->map(function (array $slice) use (&$credit) {
            if ($credit <= 0) {
                return $slice;
            }

            $applied = min($credit, $slice['amount']);
            $credit -= $applied;
            $slice['amount'] = round($slice['amount'] - $applied, 2);

            return $slice;
        })->filter(fn (array $slice) => $slice['amount'] > 0.009)->values();
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
