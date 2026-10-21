<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * When a rule change went live — written once by the migration that shipped
 * it, so it is the deploy time, not something anyone sets by hand.
 */
class Cutover extends Model
{
    public const ORDER_STATUS_SERVICE = 'order_status_service';

    protected $guarded = [];

    protected $casts = [
        'happened_at' => 'datetime',
    ];

    /** Null when the row is missing: callers treat that as "no legacy exceptions". */
    public static function at(string $key): ?CarbonInterface
    {
        return static::where('key', $key)->first()?->happened_at;
    }
}
