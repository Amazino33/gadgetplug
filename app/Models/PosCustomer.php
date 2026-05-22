<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosCustomer extends Model
{
    protected $guarded = [];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'customer_id');
    }
}
