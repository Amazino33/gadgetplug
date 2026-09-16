<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What was agreed about a figure on a statement.
 *
 * Kept beside the statement rather than written into it, so the numbers stay
 * frozen while the decisions about them accumulate. A shortage discussed twice
 * has two rows, and the second does not erase the first.
 */
class SettlementResolution extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** The money is to be paid back, on terms stated in the note. */
    public const OUTCOME_REPAYMENT_PLAN = 'repayment_plan';

    /** Sent on to be written off — not a decision this screen can make. */
    public const OUTCOME_WRITE_OFF_REFERRAL = 'write_off_referral';

    /** Looked into and there was nothing wrong. */
    public const OUTCOME_RESOLVED_NO_ISSUE = 'resolved_no_issue';

    /** The figure is not trusted; count it again before deciding. */
    public const OUTCOME_RECOUNT = 'recount';

    public const OUTCOME_OTHER = 'other';

    public const OUTCOMES = [
        self::OUTCOME_REPAYMENT_PLAN,
        self::OUTCOME_WRITE_OFF_REFERRAL,
        self::OUTCOME_RESOLVED_NO_ISSUE,
        self::OUTCOME_RECOUNT,
        self::OUTCOME_OTHER,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $resolution) {
            if (! in_array($resolution->outcome, self::OUTCOMES, true)) {
                throw new LogicException('Resolution outcome must be one of: ' . implode(', ', self::OUTCOMES) . '.');
            }

            if (blank($resolution->note)) {
                throw new LogicException('Say what was agreed. An outcome with no account of it settles nothing.');
            }
        });

        static::updating(function () {
            throw new LogicException('Resolutions are append-only. Record what changed as a new one.');
        });

        static::deleting(function () {
            throw new LogicException('Resolutions are append-only.');
        });
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(StoreSettlementStatement::class, 'store_settlement_statement_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
