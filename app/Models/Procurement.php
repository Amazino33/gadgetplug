<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Procurement extends Model
{
    protected $fillable = [
        'reference', 'vendor_id', 'supplier_id', 'waybill_image',
        'total_cost', 'amount_paid', 'payment_status', 'status',
        'void_reason', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'total_cost'   => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'approved_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        // Generate reference after insert: GP-PROC-00001
        static::created(function (self $procurement) {
            $procurement->updateQuietly([
                'reference' => 'GP-PROC-' . str_pad($procurement->id, 5, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recalculate(): void
    {
        $total  = $this->items()->selectRaw('SUM(quantity * unit_cost) as total')->value('total') ?? 0;
        $paid   = (float) $this->amount_paid;
        $status = match (true) {
            $paid >= $total && $total > 0 => 'full',
            $paid > 0                     => 'part_payment',
            default                       => 'credit',
        };

        $this->updateQuietly(['total_cost' => $total, 'payment_status' => $status]);
    }

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isVoided(): bool   { return $this->status === 'voided'; }
}
