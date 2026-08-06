<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AffiliateLevel extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'target'     => 'decimal:2',
        'rate_value' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'target', 'rate_value', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(Affiliate::class);
    }
}
