<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Whether, and for how much, we deliver somewhere.
 *
 * One row per state (lga_id null) is the default for all its LGAs; a row with
 * lga_id set overrides it for that LGA. Every change — and every override
 * cleared — is activity-logged with who did it and old → new.
 *
 * (state_id, lga_id) is unique by rule of this model (forState/forLga), not by
 * a database index, because MySQL treats each NULL lga_id as distinct.
 */
class DeliveryRule extends Model
{
    use LogsActivity;

    public const FREE          = 'free';
    public const FEE           = 'fee';
    public const NOT_DELIVERED = 'not_delivered';

    public const MODES = [self::FREE, self::FEE, self::NOT_DELIVERED];

    public const LABELS = [
        self::FREE          => 'Free',
        self::FEE           => 'Fee',
        self::NOT_DELIVERED => 'Not delivered',
    ];

    /** Filament colours: the same green / amber / grey as the delivery map's dots. */
    public const COLORS = [
        self::FREE          => 'success',
        self::FEE           => 'warning',
        self::NOT_DELIVERED => 'gray',
    ];

    /** "Free", "₦1,500" or "Not delivered" — a rule as one short phrase. */
    public static function describe(?string $mode, float|int|string|null $fee = null): string
    {
        return $mode === self::FEE
            ? \App\Support\Naira::format($fee)
            : (self::LABELS[$mode] ?? self::LABELS[self::NOT_DELIVERED]);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fee' => 'decimal:2', 'expected_cost' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            if (! in_array($rule->mode, self::MODES, true)) {
                throw new InvalidArgumentException("Unknown delivery mode [{$rule->mode}].");
            }

            if ($rule->mode === self::FEE && (float) $rule->fee <= 0) {
                throw new InvalidArgumentException('A fee rule needs a fee above ₦0.');
            }

            // Free is free; not delivered charges nothing.
            if ($rule->mode !== self::FEE) {
                $rule->fee = 0;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('delivery')
            ->logOnly(['state_id', 'lga_id', 'mode', 'fee', 'expected_cost', 'delivery_days'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function isOverride(): bool
    {
        return $this->lga_id !== null;
    }

    /** The state's default rule, created as not-delivered if it is somehow missing. */
    public static function forState(int $stateId): self
    {
        return static::query()->where('state_id', $stateId)->whereNull('lga_id')->first()
            ?? static::create(['state_id' => $stateId, 'lga_id' => null, 'mode' => self::NOT_DELIVERED]);
    }

    /** Create or update one LGA's override. */
    public static function setForLga(Lga $lga, array $values): self
    {
        $rule = static::query()->where('lga_id', $lga->id)->first() ?? new static(['state_id' => $lga->state_id, 'lga_id' => $lga->id]);
        $rule->fill(self::clean($values))->save();

        return $rule;
    }

    /** Update the state's default rule. */
    public static function setForState(int $stateId, array $values): self
    {
        $rule = static::forState($stateId);
        $rule->fill(self::clean($values))->save();

        return $rule;
    }

    /** @return array<string, mixed> */
    private static function clean(array $values): array
    {
        return [
            'mode'          => $values['mode'],
            'fee'           => filled($values['fee'] ?? null) ? round((float) $values['fee'], 2) : 0,
            'expected_cost' => filled($values['expected_cost'] ?? null) ? round((float) $values['expected_cost'], 2) : null,
            'delivery_days' => filled($values['delivery_days'] ?? null) ? trim((string) $values['delivery_days']) : null,
        ];
    }
}
