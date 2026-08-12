<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;

class BlindCountSession extends Model
{
    protected $fillable = [
        'vendor_id',
        'status',
        'frequency',
        'custom_days',
        'by_category',
        'product_order',
        'storekeeper_a_id',
        'storekeeper_b_id',
        'a_submitted_at',
        'b_submitted_at',
    ];

    protected $casts = [
        'product_order'   => 'array',
        'by_category'     => 'boolean',
        'a_submitted_at'  => 'datetime',
        'b_submitted_at'  => 'datetime',
    ];

    /**
     * The counted lines this session produced, one per product.
     *
     * Only populated for sessions counted after audit_sessions gained the link
     * (2026_08_12_100000). Older sessions return nothing rather than a guessed
     * match on vendor and timestamp.
     */
    public function auditLines(): HasMany
    {
        return $this->hasMany(AuditSession::class, 'blind_count_session_id');
    }

    /** Lines where what was counted differs from what the system expected. */
    public function varianceLines(): \Illuminate\Support\Collection
    {
        return $this->auditLines
            ->filter(fn (AuditSession $line) => ($line->countedVariance() ?? 0) !== 0)
            ->values();
    }

    /** Lines still waiting on someone: a disputed count, or an undecided case. */
    public function unresolvedCount(): int
    {
        return $this->auditLines
            ->filter(function (AuditSession $line) {
                if ($line->status === 'discrepancy') {
                    return true;
                }

                return $line->shortageCase?->awaitsDisposition() === true;
            })
            ->count();
    }

    /**
     * What the variance is worth at cost. COST-SENSITIVE — gate any display
     * behind view_cost_price.
     *
     * Uses each line's frozen case snapshot where one exists, so the figure does
     * not drift when a product is repriced. Falls back to the product's current
     * cost only for lines that never opened a case.
     */
    public function shortageValueAtCost(): float
    {
        return round($this->auditLines->sum(function (AuditSession $line) {
            if ($line->shortageCase) {
                return (float) $line->shortageCase->cost_component;
            }

            $variance = $line->countedVariance() ?? 0;

            return abs($variance) * (float) ($line->product?->cost_price ?? 0);
        }), 2);
    }

    public function hasShortfall(): bool
    {
        return $this->auditLines->contains(fn (AuditSession $line) => ($line->countedVariance() ?? 0) < 0);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function storekeeperA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_a_id');
    }

    public function storekeeperB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_b_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(BlindCountEntry::class);
    }

    // Returns the position index (1-based) of the next uncounted product for this user
    public function currentPositionFor(int $userId): int
    {
        $lastCounted = $this->entries()
            ->where('user_id', $userId)
            ->whereNotNull('count')
            ->max('position');

        return $lastCounted ? $lastCounted + 1 : 1;
    }

    // The cadence lives on the vendor, set by an owner/manager. It is deliberately
    // NOT chosen by the counter: a period restriction the restricted person picks
    // for themselves is not a control, and the old per-session dropdown meant
    // anyone wanting to recount simply selected the shortest window.
    // Null means the cadence is switched off — counts may run at any time.
    public static function cadencePeriodFor(Vendor $vendor): ?CarbonInterval
    {
        return match ($vendor->pos_blind_count_frequency ?? 'daily') {
            'none'    => null,
            'weekly'  => CarbonInterval::week(),
            'monthly' => CarbonInterval::month(),
            'custom'  => CarbonInterval::days(max(1, (int) ($vendor->pos_blind_count_custom_days ?? 1))),
            default   => CarbonInterval::day(),
        };
    }

    // The user's most recent completed count at this vendor, whichever side they
    // counted on. Null when they have never completed one.
    public static function lastCompletedFor(int $userId, Vendor $vendor): ?self
    {
        return static::where('vendor_id', $vendor->id)
            ->where('status', 'completed')
            ->where(fn ($q) => $q
                ->where('storekeeper_a_id', $userId)
                ->orWhere('storekeeper_b_id', $userId))
            ->orderByDesc('b_submitted_at')
            ->first();
    }

    // When this user may next start a count. Null means "right now" — either the
    // cadence is off, or they have no recent completed count.
    // Dates are cast immutable app-wide, so this returns the CarbonInterface
    // contract rather than the concrete Carbon class.
    public static function nextCountDueFor(int $userId, Vendor $vendor): ?CarbonInterface
    {
        $period = static::cadencePeriodFor($vendor);
        if ($period === null) return null;

        $last = static::lastCompletedFor($userId, $vendor);
        if (! $last?->b_submitted_at) return null;

        $due = $last->b_submitted_at->copy()->add($period);

        return $due->isFuture() ? $due : null;
    }

    // Blocked when the cadence has not elapsed AND no manager has authorised an
    // early re-count. The authorisation is only consumed once a session actually
    // starts, so merely viewing the page does not burn it.
    public static function isBlockedFor(int $userId, Vendor $vendor): bool
    {
        if (static::nextCountDueFor($userId, $vendor) === null) return false;

        return BlindCountAuthorization::unusedFor($userId, $vendor->id) === null;
    }
}
