<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Support\Quantity;
use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\ProcurementItemSerial;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchasePayment;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves what the Purchase Terminal sends: a draft, or a delivery received.
 *
 * A purchase is a Procurement, so everything that already reads procurements —
 * the settlement, the daily summary, the supplier list — reads these too, and
 * the old records show in Purchase History beside the new ones.
 *
 * Receiving goes through ApproveProcurementAction, the one place stock is ever
 * put on a shelf from a delivery: the store row, the cost layer, the ledger
 * entry, cost_price and price are written there exactly as they always have
 * been. What the terminal skips is the two-person review; whoever holds
 * record_purchases records and receives in one step, and is named as both.
 */
class PurchaseRecorder
{
    public const ACTION_DRAFT   = 'draft';
    public const ACTION_RECEIVE = 'receive';

    /**
     * For someone who records purchases but may not approve them: the purchase
     * is saved complete and waits, as `pending`, for an approver to receive it
     * from Purchase History. No stock, cost or price moves until then.
     */
    public const ACTION_SUBMIT  = 'submit';

    public function __construct(
        private readonly ApproveProcurementAction $approve,
        private readonly PurchaseCatalogue $catalogue,
    ) {}

    /**
     * @param  array{
     *     id?: int|null, supplier_id: int, invoice_number?: string|null,
     *     purchased_on?: string|null, notes?: string|null,
     *     amount_paid?: float|int|string|null, payment_method?: string|null,
     *     lines: array<int, array{product_id: int, product_unit_id?: int|null, quantity: int|string,
     *         unit_cost: float|int|string, selling_price: float|int|string, serials?: array<int, string>|null}>
     * }  $data
     */
    public function save(Vendor $vendor, User $user, Store $store, array $data, string $action, bool $canApprove = true): Procurement
    {
        $receive = $action === self::ACTION_RECEIVE;
        $submit  = $action === self::ACTION_SUBMIT;

        return DB::transaction(function () use ($vendor, $user, $store, $data, $receive, $submit, $canApprove) {
            $procurement = $this->draftFor($vendor, $data['id'] ?? null, $canApprove);

            Supplier::where('vendor_id', $vendor->id)->whereKey($data['supplier_id'])->firstOr(
                fn () => throw ValidationException::withMessages(['supplier_id' => 'Choose one of your suppliers.'])
            );

            // Submitted is held to the same standard as received — serials and
            // all — so the approver is never handed something incomplete.
            $lines   = $this->resolveLines($vendor, $data['lines'], $receive || $submit);
            $total   = round(array_sum(array_column($lines, 'line_total')), 2);
            // Asked for on receipt only, and never optional there: what was
            // paid, or that nothing was, is decided before stock moves.
            $payment = $receive ? $this->resolvePayment($data['payment'] ?? null, $total) : null;

            $procurement->fill([
                'vendor_id'      => $vendor->id,
                'store_id'       => $procurement->store_id ?? $store->id,
                'supplier_id'    => (int) $data['supplier_id'],
                'invoice_number' => filled($data['invoice_number'] ?? null) ? trim((string) $data['invoice_number']) : null,
                'purchased_on'   => $data['purchased_on'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'total_cost'     => $total,
                // Paid is the sum of the payment rows, written by
                // syncPayments() once there are any. A draft has none.
                'amount_paid'    => 0,
                'payment_status' => 'credit',
                'payment_method' => self::procurementMethod($payment['method'] ?? null),
                'status'         => $submit ? Procurement::STATUS_PENDING : Procurement::STATUS_DRAFT,
                'submitted_at'   => $submit ? now() : null,
            ]);
            $procurement->created_by ??= $user->id;
            $procurement->save();

            // A draft's lines are replaced whole. Nothing has been received
            // against them, so there is no history on them to preserve.
            ProcurementItemSerial::whereIn('procurement_item_id', $procurement->items()->select('id'))->delete();
            $procurement->items()->delete();

            foreach ($lines as $line) {
                $item = ProcurementItem::create([
                    'procurement_id'  => $procurement->id,
                    'product_id'      => $line['product']->id,
                    'barcode'         => $line['product']->barcode,
                    'quantity'        => $line['base_qty'],
                    'unit_cost'       => $line['base_cost'],
                    'selling_price'   => $line['selling_price'],
                    'product_unit_id' => $line['unit']?->id,
                    'unit_qty'        => $line['unit'] ? $line['qty'] : null,
                    'cost_per_unit'   => $line['unit'] ? $line['cost'] : null,
                    'unit_prices'     => $line['unit_prices'] ?: null,
                ]);

                foreach ($line['serials'] as $serial) {
                    ProcurementItemSerial::create([
                        'procurement_item_id' => $item->id,
                        'product_id'          => $line['product']->id,
                        'vendor_id'           => $vendor->id,
                        'serial'              => $serial,
                    ]);
                }
            }

            if ($receive) {
                // Handed to the approval action as an open delivery, which is
                // the only state it receives from. Inside this transaction, so
                // a refusal there (a product stocked at another branch) leaves
                // nothing behind — not even the draft's edits.
                $procurement->update(['status' => Procurement::STATUS_PENDING]);

                $this->finishReceipt($procurement, $user, $store->id, $payment);
            }

            return $procurement->fresh();
        });
    }

    /**
     * An approver receives a purchase someone else submitted: the payment is
     * chosen now, because this is when the goods go on the shelf.
     *
     * @param  array<string, mixed>|null  $payment
     */
    public function receiveSubmitted(Procurement $procurement, User $user, Store $store, ?array $payment): Procurement
    {
        return DB::transaction(function () use ($procurement, $user, $store, $payment) {
            $row = Procurement::whereKey($procurement->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isAwaitingApproval()) {
                throw ValidationException::withMessages([
                    'id' => "{$row->reference} is not waiting for approval.",
                ]);
            }

            $this->finishReceipt($row, $user, $store->id, $this->resolvePayment($payment, (float) $row->total_cost));

            return $row->fresh();
        });
    }

    /**
     * Stock on the shelf, prices set, payment taken — the one place a purchase
     * is received, whether straight from the terminal or after approval.
     *
     * @param  array{amount: float, method: string|null, note: string|null}  $payment
     */
    private function finishReceipt(Procurement $procurement, User $user, int $storeId, array $payment): void
    {
        $this->approve->execute($procurement, $storeId);

        $procurement->forceFill([
            'approved_by'    => $user->id,
            'approved_at'    => $procurement->approved_at ?? now(),
            'payment_method' => self::procurementMethod($payment['method']),
        ])->save();

        // The other units' selling prices, set on the line's prices panel and
        // kept on the line. The base unit's is the line's selling_price, which
        // the approval above has already written to the product.
        foreach ($procurement->items()->get(['product_id', 'unit_prices']) as $item) {
            foreach ($item->unit_prices ?? [] as $unitId => $price) {
                ProductUnit::whereKey((int) $unitId)
                    ->where('product_id', $item->product_id)
                    ->update(['price' => $price]);
            }
        }

        if ($payment['amount'] > 0) {
            PurchasePayment::create([
                'purchase_id' => $procurement->id,
                'vendor_id'   => $procurement->vendor_id,
                'amount'      => $payment['amount'],
                'method'      => $payment['method'],
                'paid_at'     => now(),
                'recorded_by' => $user->id,
                'note'        => $payment['note'],
            ]);
        }

        $procurement->syncPayments();
    }

    /**
     * Pay a supplier later, against a purchase already received.
     *
     * @param  array{amount: float|int|string, method: string, note?: string|null}  $data
     */
    public function recordPayment(Procurement $procurement, User $user, array $data): PurchasePayment
    {
        return DB::transaction(function () use ($procurement, $user, $data) {
            $row = Procurement::whereKey($procurement->getKey())->lockForUpdate()->firstOrFail();

            if ($row->isDraft() || $row->isVoided()) {
                throw ValidationException::withMessages([
                    'amount' => 'Payments are recorded against purchases that have been received.',
                ]);
            }

            $amount  = round((float) $data['amount'], 2);
            $balance = $row->balance();

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount paid.']);
            }

            if ($amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => 'That is more than the ₦'.number_format($balance, 2).' still owed.',
                ]);
            }

            $payment = PurchasePayment::create([
                'purchase_id' => $row->id,
                'vendor_id'   => $row->vendor_id,
                'amount'      => $amount,
                'method'      => $data['method'],
                'paid_at'     => now(),
                'recorded_by' => $user->id,
                'note'        => $data['note'] ?? null,
            ]);

            $row->syncPayments();

            return $payment;
        });
    }

    /**
     * Paid in full, part paid, or not paid — one of the three, always.
     *
     * @return array{amount: float, method: string|null, note: string|null}
     */
    private function resolvePayment(?array $payment, float $total): array
    {
        $option = $payment['option'] ?? null;

        if (! in_array($option, ['full', 'part', 'none'], true)) {
            throw ValidationException::withMessages([
                'payment.option' => 'Choose how this purchase was paid before receiving it.',
            ]);
        }

        if ($option === 'none') {
            return ['amount' => 0.0, 'method' => null, 'note' => null];
        }

        $method = $payment['method'] ?? null;

        if (! array_key_exists((string) $method, PurchasePayment::METHODS)) {
            throw ValidationException::withMessages(['payment.method' => 'Choose how it was paid.']);
        }

        $amount = $option === 'full' ? $total : round((float) ($payment['amount'] ?? 0), 2);

        if ($option === 'part' && ($amount <= 0 || $amount >= $total)) {
            throw ValidationException::withMessages([
                'payment.amount' => 'A part payment is more than ₦0 and less than the total of ₦'.number_format($total, 2).'.',
            ]);
        }

        return ['amount' => $amount, 'method' => $method, 'note' => $payment['note'] ?? null];
    }

    /**
     * The purchase's own payment_method, which the settlement reads — 'cash'
     * there flags stock that may have been paid from the till.
     */
    private static function procurementMethod(?string $method): string
    {
        return match ($method) {
            'cash'     => 'cash',
            'transfer' => 'bank_transfer',
            'pos'      => 'pos',
            'other'    => 'other',
            default    => 'credit',
        };
    }

    /** The draft being continued, locked, or a new purchase. */
    private function draftFor(Vendor $vendor, mixed $id, bool $canApprove = true): Procurement
    {
        if (blank($id)) {
            return new Procurement();
        }

        $procurement = Procurement::where('vendor_id', $vendor->id)
            ->whereKey((int) $id)
            ->lockForUpdate()
            ->first();

        if (! $procurement) {
            throw ValidationException::withMessages(['id' => 'That draft is no longer available.']);
        }

        // A submitted purchase can be reopened — corrected, received, or
        // pulled back to a draft — by someone who may approve it.
        if ($procurement->isAwaitingApproval() && $canApprove) {
            return $procurement;
        }

        if (! $procurement->isDraft()) {
            throw ValidationException::withMessages([
                'id' => "{$procurement->reference} has already been received and cannot be changed.",
            ]);
        }

        return $procurement;
    }

    /**
     * Every line checked and converted to base units before anything is
     * written, so the person sees every problem at once.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array<int, array{product: Product, unit: ProductUnit|null, qty: int, cost: float,
     *     base_qty: int, base_cost: float, line_total: float, selling_price: float, serials: array<int, string>}>
     */
    private function resolveLines(Vendor $vendor, array $input, bool $receive): array
    {
        $products = $this->catalogue->receivable($vendor)
            ->whereKey(array_column($input, 'product_id'))
            ->get()
            ->keyBy('id');

        $errors = [];
        $lines  = [];
        $seen   = [];

        foreach ($input as $i => $row) {
            $product = $products->get((int) $row['product_id']);

            if (! $product) {
                $errors["lines.{$i}.product_id"] = 'That product is not one this shop can receive.';
                continue;
            }

            $unit = null;

            if (filled($row['product_unit_id'] ?? null)) {
                $unit = $product->units->firstWhere('id', (int) $row['product_unit_id']);

                if (! $unit) {
                    $errors["lines.{$i}.product_unit_id"] = "That unit does not belong to {$product->name}.";
                    continue;
                }
            }

            $factor  = $unit ? max(1, (int) $unit->conversion_factor) : 1;
            $qty     = Quantity::of($row['quantity']);
            $cost    = round((float) $row['unit_cost'], 2);
            $baseQty = Quantity::mul($qty, $factor);

            $serials = collect($row['serials'] ?? [])
                ->map(fn ($s) => trim((string) $s))
                ->filter()
                ->values();

            if (! $product->track_serial) {
                $serials = collect();
            } elseif ($receive) {
                if ($serials->count() !== $baseQty) {
                    $errors["lines.{$i}.serials"] = sprintf(
                        '%s needs %d serial/IMEI number%s — %d entered.',
                        $product->name, $baseQty, $baseQty === 1 ? '' : 's', $serials->count(),
                    );
                }

                foreach ($serials as $serial) {
                    if (isset($seen[$product->id][$serial]) || $serials->filter(fn ($s) => $s === $serial)->count() > 1) {
                        $errors["lines.{$i}.serials"] = "Serial {$serial} is entered more than once.";
                    }
                    $seen[$product->id][$serial] = true;
                }

                $taken = ProcurementItemSerial::query()
                    ->where('vendor_id', $vendor->id)
                    ->where('product_id', $product->id)
                    ->whereIn('serial', $serials->all())
                    ->whereHas('item.procurement', fn ($q) => $q->where('status', Procurement::STATUS_APPROVED))
                    ->pluck('serial');

                if ($taken->isNotEmpty()) {
                    $errors["lines.{$i}.serials"] = 'Already received: '.$taken->implode(', ').'.';
                }
            }

            // Selling prices for the product's other units, from the prices
            // panel, keyed by unit id. The base unit's is selling_price.
            $unitPrices = [];

            foreach ($row['unit_prices'] ?? [] as $up) {
                $unitId = (int) ($up['unit_id'] ?? 0);
                $match  = $product->units->firstWhere('id', $unitId);

                if (! $match) {
                    $errors["lines.{$i}.unit_prices"] = "A price was given for a unit {$product->name} does not have.";
                    continue;
                }

                if (! $match->is_base) {
                    $unitPrices[$unitId] = round((float) $up['price'], 2);
                }
            }

            $lines[] = [
                'unit_prices'   => $unitPrices,
                'product'       => $product,
                'unit'          => $unit,
                'qty'           => $qty,
                'cost'          => $cost,
                'base_qty'      => $baseQty,
                // Per base unit, which is what stock, cost layers and
                // cost_price are all kept in: ₦3,600 a carton of 12 is ₦300.
                'base_cost'     => round($cost / $factor, 2),
                'line_total'    => round($qty * $cost, 2),
                'selling_price' => round((float) $row['selling_price'], 2),
                'serials'       => $serials->all(),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
