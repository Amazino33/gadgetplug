<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

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

    // Checks if the user is blocked from starting a new session based on frequency
    public static function isBlockedFor(int $userId, int $vendorId, string $frequency, ?int $customDays): bool
    {
        $cutoff = match ($frequency) {
            'daily'   => now()->subDay(),
            'weekly'  => now()->subWeek(),
            'monthly' => now()->subMonth(),
            'custom'  => now()->subDays($customDays ?? 1),
        };

        return static::where('vendor_id', $vendorId)
            ->where('status', 'completed')
            ->where(function ($q) use ($userId) {
                $q->where('storekeeper_a_id', $userId)
                  ->orWhere('storekeeper_b_id', $userId);
            })
            ->where('b_submitted_at', '>=', $cutoff)
            ->exists();
    }
}
