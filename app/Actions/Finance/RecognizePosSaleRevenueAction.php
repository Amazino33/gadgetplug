<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Services\FinancialLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

// The POS equivalent of RecognizeOrderRevenueAction (app/Actions/Finance) — a
// POS sale is money in hand the moment it completes, no delivery step to wait
// for, so recognition happens right in PosSaleController::store() rather than
// on a status transition.
//
// A split sale has no single account to credit: each payment row is posted
// as its own ledger source (not the sale itself), so a cash+card split
// correctly lands part in the cash account and part in the bank account
// without FinancialLedger's (source_type, source_id, direction) uniqueness
// colliding between the two legs.
//
// Never throws — a missing financial account or a ledger failure is logged
// and the sale still completes. The till handed real goods to a real
// customer standing in front of it; a bookkeeping gap must never roll that
// back the way a stock-adjustment failure legitimately does.
class RecognizePosSaleRevenueAction
{
    public function execute(PosSale $sale): void
    {
        // Nothing was collected, so there is nothing to recognise. The goods
        // left and the cost books at stock-out either way; the revenue arrives
        // only when the customer actually pays, which is what makes an open
        // debt honestly drag the period instead of flattering it.
        if ($sale->payment_method === 'debt') {
            return;
        }

        if ($sale->payment_method === 'split') {
            $sale->loadMissing('payments');

            foreach ($sale->payments as $payment) {
                // The unpaid slice of a part-paid sale, deferred for the same
                // reason. Its charge is already on the customer's ledger, and
                // each repayment recognises itself as it lands.
                if ($payment->method === 'debt') {
                    continue;
                }

                // Change is always handed back in cash regardless of how the
                // customer paid, so only the cash leg of a split can be
                // reduced by it — the same cash-specific meaning
                // amount_tendered/change_given already carry in store().
                $amount = $payment->method === 'cash'
                    ? max(0, (float) $payment->amount - (float) $sale->change_given)
                    : (float) $payment->amount;

                $this->post($sale->vendor_id, $payment, $amount, $payment->method, "Split payment — sale {$sale->reference}");
            }

            return;
        }

        $this->post($sale->vendor_id, $sale, (float) $sale->total, $sale->payment_method, "POS sale — {$sale->reference}");
    }

    // Reverses every 'in' entry the sale posted — its own for a plain sale,
    // or one per payment row for a split. Never edits the original entry,
    // posts a matching 'out' sourced from that entry itself, same discipline
    // as OrderObserver::applyRevenueReversal().
    public function reverseForVoid(PosSale $sale): void
    {
        $sources = $sale->payment_method === 'split'
            ? $sale->loadMissing('payments')->payments
            : collect([$sale]);

        foreach ($sources as $source) {
            $original = FinancialLedgerEntry::where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('direction', 'in')
                ->first();

            if (! $original) {
                continue;
            }

            try {
                FinancialLedger::postEntry(
                    account: $original->account,
                    direction: 'out',
                    amount: (float) $original->amount,
                    source: $original,
                    description: "Reversal — POS sale {$sale->reference} voided",
                    createdBy: auth()->id(),
                );
            } catch (Throwable $e) {
                Log::error("POS revenue reversal failed for sale {$sale->id}: ".$e->getMessage());
            }
        }
    }

    // A return doesn't touch the original sale's ledger entries — it posts
    // its own 'out', sourced from the PosReturn itself, so repeated partial
    // returns against the same sale each get their own entry instead of
    // colliding on (source_type, source_id, direction). store_credit moves
    // no real money out of either account, so it posts nothing.
    public function reverseForReturn(PosSale $sale, PosReturn $posReturn): void
    {
        if ($posReturn->refund_method === 'store_credit') {
            return;
        }

        $type = $posReturn->refund_method === 'cash' ? 'cash' : 'bank';
        $account = FinancialAccount::where('vendor_id', $sale->vendor_id)->where('type', $type)->first();

        if (! $account) {
            Log::error("Return refund not posted for {$posReturn->reference}: no {$type} account found for vendor {$sale->vendor_id}.");

            return;
        }

        try {
            FinancialLedger::postEntry(
                account: $account,
                direction: 'out',
                amount: (float) $posReturn->refund_amount,
                source: $posReturn,
                description: "Refund — {$posReturn->reference} (sale {$sale->reference})",
                createdBy: auth()->id(),
            );
        } catch (Throwable $e) {
            Log::error("Return refund posting failed for {$posReturn->reference}: ".$e->getMessage());
        }
    }

    private function post(int $vendorId, Model $source, float $amount, string $method, string $description): void
    {
        if ($amount <= 0) {
            return;
        }

        // Belt and braces. The callers above already skip debt, but the mapping
        // below sends anything that is not cash to the bank account — so a debt
        // tender reaching here would post money into the books that nobody
        // handed over, and post() logs rather than throws, so it would do it
        // silently. Refuse it here too rather than rely on every future caller
        // remembering.
        if ($method === 'debt') {
            return;
        }

        $type = $method === 'cash' ? 'cash' : 'bank';
        $account = FinancialAccount::where('vendor_id', $vendorId)->where('type', $type)->first();

        if (! $account) {
            Log::error("POS revenue not posted for vendor {$vendorId}: no {$type} account found.");

            return;
        }

        try {
            FinancialLedger::postEntry(
                account: $account,
                direction: 'in',
                amount: $amount,
                source: $source,
                description: $description,
                createdBy: auth()->id(),
            );
        } catch (Throwable $e) {
            Log::error("POS revenue posting failed for vendor {$vendorId}: ".$e->getMessage());
        }
    }
}
