<?php

namespace App\Models\Assistant;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question the assistant could not answer, kept for review: it is the list
 * of tools and data worth building next.
 */
class AssistantUnansweredQuestion extends Model
{
    public const REASONS = ['no_tool', 'no_data', 'ambiguous', 'refused', 'error', 'too_many_steps'];

    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssistantQuestion::class, 'question_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
