<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Append-only ledger, deliberately identical in discipline to
// WalletTransaction — but a SEPARATE economy. Points are not money and never
// touch a balance the affiliate can be paid out; the only bridge is an
// explicit conversion (see PointConversionService). Balances are always
// derived by summing `points` here, never stored as a mutable field, and rows
// are never corrected in place — a mistake gets a compensating row.
class PlugPointTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'points' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('PlugPointTransaction rows are append-only and can never be updated. Write a compensating row instead.');
        });
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function taskSubmission(): BelongsTo
    {
        return $this->belongsTo(AffiliateTaskSubmission::class, 'affiliate_task_submission_id');
    }
}
