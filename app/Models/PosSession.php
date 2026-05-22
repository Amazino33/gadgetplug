<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PosSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'closing_float' => 'decimal:2',
        'opened_at'     => 'datetime',
        'closed_at'     => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function zReport(): HasOne
    {
        return $this->hasOne(PosZReport::class);
    }
}
