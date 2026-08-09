<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use LogsActivity;

    public const CATEGORIES = [
        'advertising'      => 'Advertising',
        'logistics_other'  => 'Logistics (Other)',
        'other'            => 'Other',
    ];

    protected $guarded = [];

    protected $casts = [
        'amount'      => 'decimal:2',
        'incurred_at' => 'date',
        'posted_at'   => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['category', 'amount', 'description', 'incurred_at', 'financial_account_id', 'posted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->vendor_id = $this->vendor_id;
    }

    protected static function booted(): void
    {
        // getOriginal(), not the live attribute — same reasoning as Order's
        // and ProcurementLogisticsLeg's guards: only refuses a change on a
        // row already posted before this update began, not a simultaneous
        // first-time set of amount + financial_account_id/posted_at.
        // financial_account_id is guarded too, not just category/amount —
        // silently switching which account a posted expense came from would
        // misrepresent history, since the ledger entry already tied to the
        // original account doesn't move with it.
        static::updating(function (self $expense) {
            if ($expense->getOriginal('posted_at') !== null && (
                $expense->isDirty('category') ||
                $expense->isDirty('amount') ||
                $expense->isDirty('financial_account_id')
            )) {
                throw new LogicException('A posted expense\'s category/amount/account cannot be changed. Make a manual ledger correction instead.');
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }
}
