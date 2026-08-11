<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Append-only ledger. Balances are always derived by summing `amount` here
// (see WalletService) — never stored as a mutable field, and rows are never
// corrected in place. A mistake gets a compensating reversal row, not an edit.
class WalletTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('WalletTransaction rows are append-only and can never be updated. Write a compensating reversal row instead.');
        });
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(AffiliateCommission::class, 'affiliate_commission_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayout::class, 'affiliate_payout_id');
    }

    public function taskSubmission(): BelongsTo
    {
        return $this->belongsTo(AffiliateTaskSubmission::class, 'affiliate_task_submission_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(AffiliateClick::class, 'affiliate_click_id');
    }
}
