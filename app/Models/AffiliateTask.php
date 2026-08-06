<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AffiliateTask extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'auto_target'                   => 'decimal:2',
        'reward_amount'                 => 'decimal:2',
        'counts_toward_level'           => 'boolean',
        'level_progress_value'          => 'decimal:2',
        'is_active'                     => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'verification_type', 'auto_metric', 'auto_target', 'reward_amount', 'counts_toward_level', 'level_progress_value', 'max_completions_per_affiliate', 'cooldown_days', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AffiliateTaskSubmission::class);
    }
}
