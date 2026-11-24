<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Support\Quantity;
use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\PurchasePayment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase History: every procurement this person can reach, old and new.
 *
 * Old wizard deliveries are the same rows, so they appear here with no
 * migration of any kind; they simply have no invoice number or purchase date,
 * and fall back to their notes and created date.
 */
class PurchaseHistory
{
    public const STATUS_LABELS = [
        Procurement::STATUS_DRAFT             => 'Draft',
        Procurement::STATUS_PENDING           => 'Awaiting Check',
        Procurement::STATUS_CHANGES_REQUESTED => 'Sent Back',
        Procurement::STATUS_APPROVED          => 'Received',
        Procurement::STATUS_VOIDED            => 'Voided',
    ];

    /**
     * The same branch rule as the Procurements list: your branches, orders
     * with no branch (they predate the field), and orders you raised.
     */
    public function reachable(Vendor $vendor, User $user): Builder
    {
        $branches = ActiveStore::accessibleFor($vendor, $user)->pluck('id');

        return Procurement::query()
            ->where('procurements.vendor_id', $vendor->id)
            ->where(fn (Builder $q) => $q
                ->whereIn('procurements.store_id', $branches)
                ->orWhereNull('procurements.store_id')
                ->orWhere('procurements.created_by', $user->id));
    }

    /** @param  array<string, mixed>  $filters */
    public function list(Vendor $vendor, User $user, array $filters): LengthAwarePaginator
    {
        $dated = 'COALESCE(procurements.purchased_on, DATE(procurements.created_at))';

        return $this->reachable($vendor, $user)
            ->with(['supplier:id,name', 'approver:id,name', 'creator:id,name'])
            ->withCount('items')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereRaw("{$dated} >= ?", [$from]))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereRaw("{$dated} <= ?", [$to]))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('procurements.supplier_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('procurements.status', $status))
            ->when($filters['payment'] ?? null, fn ($q, $payment) => $q->where('procurements.payment_status', $payment))
            ->when(trim((string) ($filters['q'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $q->where(fn (Builder $w) => $w
                    ->where('procurements.reference', 'like', $like)
                    ->orWhere('procurements.invoice_number', 'like', $like)
                    ->orWhereHas('items.product', fn (Builder $p) => $p
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('barcode', 'like', $like)));
            })
            ->orderByDesc('procurements.created_at')
            ->orderByDesc('procurements.id')
            ->paginate(25);
    }

    /** @return array<string, mixed> */
    public function row(Procurement $p, bool $canApprove = false): array
    {
        $awaiting = $p->isAwaitingApproval();

        $total = (float) $p->total_cost;
        $paid  = (float) $p->amount_paid;

        return [
            'id'             => $p->id,
            'date'           => ($p->purchased_on ?? $p->created_at)?->toDateString(),
            'reference'      => $p->reference,
            'invoice_number' => $p->invoice_number,
            'supplier'       => $p->supplier?->name,
            'items_count'    => (int) ($p->items_count ?? $p->items()->count()),
            'total'          => $total,
            'paid'           => $paid,
            'balance'        => round(max(0, $total - $paid), 2),
            'status'         => $p->status,
            // Submitted from the terminal: waiting on an approver, not on the
            // old two-person check, so it says what it is waiting for.
            'status_label'   => $awaiting ? 'Awaiting Approval' : (self::STATUS_LABELS[$p->status] ?? ucfirst((string) $p->status)),
            'awaiting_approval' => $awaiting,
            'can_receive'    => $awaiting && $canApprove,
            'can_resume'     => $p->isDraft() || ($awaiting && $canApprove),
            // A draft owes nothing yet, so it has no payment state to show.
            'payment_status' => $p->isDraft() ? null : $p->payment_status,
            'payment_label'  => $p->isDraft() ? null : (Procurement::PAYMENT_LABELS[$p->payment_status] ?? null),
            // Paid once the goods are in. Not while a submission waits.
            'can_pay'        => ! $p->isDraft() && ! $p->isVoided() && ! $awaiting && $total - $paid > 0.004,
            'received_by'    => $p->isApproved() ? $p->approver?->name : null,
            'recorded_by'    => $p->creator?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Vendor $vendor, Procurement $p, bool $canApprove = false): array
    {
        $p->loadMissing([
            'supplier', 'approver:id,name', 'creator:id,name', 'store:id,name',
            'items.product.units', 'items.productUnit', 'items.serials', 'items.corrections',
            'payments.recorder:id,name',
        ]);

        $open = $p->isOpen();

        return $this->row($p, $canApprove) + [
            'supplier_id'    => $p->supplier_id,
            'store'          => $p->store?->name,
            'purchased_on'   => $p->purchased_on?->toDateString(),
            'notes'          => $p->notes,
            'payment_method' => $p->payment_method,
            'received_at'    => $p->approved_at?->toIso8601String(),
            'created_at'     => $p->created_at?->toIso8601String(),
            'vendor'         => ['name' => $vendor->name],
            // Deliveries from the old two-person flow still waiting to be
            // agreed are answered on the screen built for that, not here.
            'submitted_at'   => $p->submitted_at?->toIso8601String(),
            'review_url'     => $open && ! $p->isAwaitingApproval()
                ? ProcurementResource::getUrl('view', ['record' => $p, 'tenant' => $vendor->slug], panel: 'vendor')
                : null,
            'payments'       => $p->payments->map(fn (PurchasePayment $pay) => [
                'id'           => $pay->id,
                'amount'       => (float) $pay->amount,
                'method'       => $pay->method,
                'method_label' => PurchasePayment::METHODS[$pay->method] ?? ucfirst($pay->method),
                'paid_at'      => $pay->paid_at?->toIso8601String(),
                'recorded_by'  => $pay->recorder?->name,
                'note'         => $pay->note,
            ])->values(),
            'lines'          => $p->items->map(fn (ProcurementItem $item) => $this->line($item, $p->store_id))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function line(ProcurementItem $item, ?int $storeId): array
    {
        $unit    = $item->productUnit;
        $factor  = $unit ? max(1, (int) $unit->conversion_factor) : 1;
        $baseQty = $item->verifiedQuantity();

        return [
            'id'              => $item->id,
            'product_id'      => $item->product_id,
            'product_name'    => $item->product?->name ?? 'Deleted product',
            'barcode'         => $item->product?->barcode ?? $item->barcode,
            'sku'             => $item->product?->sku,
            'unit_id'         => $unit?->id,
            'unit_name'       => $unit?->name ?? ($item->product?->base_unit ?: 'Piece'),
            'factor'          => $factor,
            // In the unit it was bought in, when that was recorded; otherwise
            // base units, which is all an old wizard line knew.
            'unit_qty'        => $item->unit_qty ?? $baseQty,
            'cost_per_unit'   => (float) ($item->cost_per_unit ?? $item->verifiedUnitCost()),
            'base_qty'        => $baseQty,
            'base_cost'       => $item->verifiedUnitCost(),
            'line_total'      => $item->cost_per_unit !== null && $item->unit_qty !== null && ! $item->isCorrected()
                ? round((float) $item->cost_per_unit * Quantity::of($item->unit_qty), 2)
                : $item->verifiedLineTotal(),
            'selling_price'   => (float) $item->selling_price,
            'unit_prices'     => $item->unit_prices ?? [],
            'serials'         => $item->serials->pluck('serial')->values(),
            // An old wizard line kept its IMEI/serial in the barcode field.
            'legacy_serial'   => $item->barcode && $item->barcode !== $item->product?->barcode ? $item->barcode : null,
            // Formatted as the terminal sees products, so a draft reopens with
            // the shelf as it stands now, not as it stood when it was saved.
            'product'         => $item->product ? app(PurchaseCatalogue::class)->format($item->product, null, $storeId) : null,
        ];
    }
}
