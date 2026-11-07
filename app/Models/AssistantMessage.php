<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'user_id',
        'store_id',
        'store_name_snapshot',
        'role',
        'content',
        'is_prompt',
        'ai_meta',
        'flagged_at',
        'flagged_by',
        'flag_note',
    ];

    protected $casts = [
        'is_prompt' => 'boolean',
        'ai_meta' => 'array',
        'flagged_at' => 'datetime',
    ];

    public function scopePrompts(Builder $query): Builder
    {
        return $query->where('is_prompt', true);
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->whereNotNull('flagged_at');
    }

    public function isFlagged(): bool
    {
        return ! is_null($this->flagged_at);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AssistantThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function queryRuns(): HasMany
    {
        return $this->hasMany(AssistantQueryRun::class, 'message_id');
    }
}
