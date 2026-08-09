<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProcurementLogisticsLeg extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'    => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Posting metadata (financial_account_id/posted_at) may still be set —
        // that's the posting action itself. Only the recorded amount/route are
        // frozen once posted, matching "a posted leg's amount is immutable."
        // In practice there is no UI path to reach this after creation anyway
        // (procurements have no edit page), but this guards direct model use.
        static::updating(function (self $leg) {
            // getOriginal(), not the live attribute — see Order's identical
            // guard for why: only refuses a change on a row already posted
            // before this update began, not a simultaneous first-time set.
            if ($leg->getOriginal('posted_at') !== null && ($leg->isDirty('amount') || $leg->isDirty('route_label'))) {
                throw new LogicException('A posted logistics leg\'s amount/route cannot be changed. Void the procurement and re-record it, or make a manual ledger correction.');
            }
        });
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }
}
