<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide defaults for in-app tips. One row, same pattern as
 * FreePlanSetting and AssistantGlobalSetting.
 */
class TipSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'daily_cap' => 'integer',
        'adoption_window_days' => 'integer',
        'cooldown_days' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'daily_cap' => 1,
            'adoption_window_days' => 7,
            'cooldown_days' => 3,
        ]);
    }

    /** Zero is allowed: it is how an admin switches every pop-up off at once. */
    public static function dailyCap(): int
    {
        return max(0, (int) static::current()->daily_cap);
    }

    public static function adoptionWindowDays(): int
    {
        return max(1, (int) static::current()->adoption_window_days);
    }

    public static function cooldownDays(): int
    {
        return max(0, (int) static::current()->cooldown_days);
    }
}
