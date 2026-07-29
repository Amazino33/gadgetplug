<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A manager's one-shot permission for a specific user to start a count before
// the vendor's cadence would normally allow it. Consumed (used_at set) rather
// than deleted, so the grant survives as an audit record.
class BlindCountAuthorization extends Model
{
    protected $fillable = [
        'vendor_id',
        'user_id',
        'granted_by_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    public static function unusedFor(int $userId, int $vendorId): ?self
    {
        return static::where('vendor_id', $vendorId)
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->latest('id')
            ->first();
    }
}
