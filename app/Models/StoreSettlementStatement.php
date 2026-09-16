<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The figures as they stood when somebody printed them.
 *
 * Frozen on purpose. A statement that quietly updated itself as later
 * corrections landed would make it impossible to ask what was actually agreed
 * at a settlement — and being able to ask that is the entire point of printing
 * one.
 */
class StoreSettlementStatement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'        => 'array',
        'period_start'   => 'datetime',
        'period_end'     => 'datetime',
        'generated_at'   => 'datetime',
        'expected_cash'  => 'decimal:2',
        'confirmed_cash' => 'decimal:2',
        'true_shortage'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(function (self $statement) {
            $statement->updateQuietly([
                'reference' => 'GP-STMT-' . str_pad((string) $statement->id, 6, '0', STR_PAD_LEFT),
            ]);
        });

        static::updating(function (self $statement) {
            // The reference is stamped from the id, which only exists after the
            // row does. Nothing else may ever change.
            $changing = array_values(array_diff(array_keys($statement->getDirty()), ['updated_at']));

            if ($changing === ['reference'] && blank($statement->getOriginal('reference'))) {
                return;
            }

            throw new LogicException('A statement is a photograph of a moment. Generate a fresh one instead of editing it.');
        });

        static::deleting(function () {
            throw new LogicException('Statements are never deleted — what was agreed at a settlement would go with them.');
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** What people decided to do about the figures, appended over time. */
    public function resolutions(): HasMany
    {
        return $this->hasMany(SettlementResolution::class)->oldest('id');
    }

    /** Reach into the frozen figures without unpacking the whole payload. */
    public function figure(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }
}
