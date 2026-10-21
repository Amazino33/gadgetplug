<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A local government area, with one approximate reference point — good for
 * straight-line distance and a map marker, never routing or boundaries.
 */
class Lga extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** This LGA's own override, if it has one. */
    public function overrideRule(): HasOne
    {
        return $this->hasOne(DeliveryRule::class);
    }
}
