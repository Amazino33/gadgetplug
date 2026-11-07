<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'store_removed_at',
        'store_removed_name',
        'title',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'store_removed_at' => 'datetime',
    ];

    public function hasDeletedStore(): bool
    {
        return $this->store_removed_at !== null && $this->store_id === null;
    }

    public function scopeWithDeletedStore($query)
    {
        return $query->whereNotNull('store_removed_at')->whereNull('store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'thread_id')->orderBy('created_at');
    }
}
