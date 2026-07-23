<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlindCountEntry extends Model
{
    protected $fillable = [
        'blind_count_session_id',
        'user_id',
        'product_id',
        'position',
        'count',
        'note',
        'counted_at',
    ];

    protected $casts = [
        'counted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BlindCountSession::class, 'blind_count_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
