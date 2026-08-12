<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePointConversion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'points_spent'    => 'integer',
        'naira_per_point' => 'decimal:4',
        'amount'          => 'decimal:2',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
