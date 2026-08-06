<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommissionItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate'        => 'decimal:2',
        'base_amount' => 'decimal:2',
        'amount'      => 'decimal:2',
    ];

    public function commission(): BelongsTo
    {
        return $this->belongsTo(AffiliateCommission::class, 'affiliate_commission_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
