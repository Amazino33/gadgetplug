<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AffiliateReachBand extends Model
{
    // Retuning a band changes what every future share pays, so the change is
    // logged the same way AffiliateTask's is.
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'min_reach' => 'integer',
        'max_reach' => 'integer',
        'points'    => 'integer',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'min_reach', 'max_reach', 'points', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The active band a reported reach falls into, or null if the bands don't
     * cover it. Highest matching min_reach wins, so a null-max top band
     * behaves as open-ended without needing a sentinel value.
     */
    public static function forReach(int $reach): ?self
    {
        return static::active()
            ->where('min_reach', '<=', $reach)
            ->where(fn (Builder $q) => $q->whereNull('max_reach')->orWhere('max_reach', '>=', $reach))
            ->orderByDesc('min_reach')
            ->first();
    }
}
