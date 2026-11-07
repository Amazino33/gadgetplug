<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'active_store_id',
        'admin_disabled',
        'vendor_disabled',
    ];

    protected $casts = [
        'admin_disabled' => 'boolean',
        'vendor_disabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'active_store_id');
    }
}
