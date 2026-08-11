<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateClick extends Model
{
    protected $guarded = [];

    protected $casts = [
        'page_views'    => 'integer',
        'qualified_at'  => 'datetime',
        'reward_amount' => 'decimal:2',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'affiliate_click_id');
    }

    /** Clicks that went past the landing page and were settled. */
    public function scopeEngaged(Builder $query): Builder
    {
        return $query->whereNotNull('qualified_at');
    }

    /** Engaged clicks that actually paid — i.e. weren't zeroed by a cap. */
    public function scopeRewarded(Builder $query): Builder
    {
        return $query->engaged()->where('reward_amount', '>', 0);
    }
}
