<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSession extends Model
{
    protected $fillable = [
        'vendor_id',
        'product_id',
        'storekeeper_a_id',
        'count_a',
        'storekeeper_b_id',
        'count_b',
        'manager_id',
        'manager_override_count',
        'status',
        'reason_code',
        'loss_value',
    ];

    protected $casts = [
        'loss_value' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storekeeperA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_a_id');
    }

    public function storekeeperB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_b_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // ---- Helpers for our logic ----
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function hasDiscrepancy(): bool
    {
        return $this->status === 'discrepancy';
    }
}
