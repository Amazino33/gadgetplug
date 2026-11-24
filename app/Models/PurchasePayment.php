<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a supplier against one purchase. A purchase can have several. */
class PurchasePayment extends Model
{
    public const METHODS = [
        'cash'     => 'Cash',
        'transfer' => 'Transfer',
        'pos'      => 'POS',
        'other'    => 'Other',
    ];

    protected $fillable = ['purchase_id', 'vendor_id', 'amount', 'method', 'paid_at', 'recorded_by', 'note'];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'purchase_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
