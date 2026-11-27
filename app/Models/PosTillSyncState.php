<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one till last reported it holds unsynced at one branch. */
class PosTillSyncState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pending_sales'     => 'integer',
        'oldest_pending_at' => 'datetime',
        'reported_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
