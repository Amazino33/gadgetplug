<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A Nigerian state (or the FCT). Seeded from database/data/nigeria-locations.json. */
class State extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class)->orderBy('name');
    }

    /** The state's default delivery rule (the one with no LGA). */
    public function defaultRule(): HasOne
    {
        return $this->hasOne(DeliveryRule::class)->whereNull('lga_id');
    }

    /** How the state is named in an address: the FCT is not a "State". */
    public function addressName(): string
    {
        return $this->name === 'Federal Capital Territory' ? 'FCT' : $this->name.' State';
    }
}
