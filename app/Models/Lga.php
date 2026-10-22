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

    /**
     * The rule that applies here — the override, else the state's default —
     * with where it came from. Needs overrideRule and state.defaultRule loaded
     * to avoid a query per LGA.
     *
     * @return array{mode: string, fee: float, expected_cost: ?float, delivery_days: ?string, source: string}
     */
    public function effectiveRule(): array
    {
        $rule = $this->overrideRule ?? $this->state?->defaultRule;

        return [
            'mode'          => $rule?->mode ?? DeliveryRule::NOT_DELIVERED,
            'fee'           => $rule && $rule->mode === DeliveryRule::FEE ? (float) $rule->fee : 0.0,
            'expected_cost' => $rule?->expected_cost !== null ? (float) $rule->expected_cost : null,
            'delivery_days' => $rule?->delivery_days,
            'source'        => $this->overrideRule ? 'lga' : ($rule ? 'state' : 'none'),
        ];
    }
}
