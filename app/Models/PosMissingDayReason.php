<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Why a cashier started selling without recording an earlier end of day. */
class PosMissingDayReason extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'given_on'      => 'date',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
