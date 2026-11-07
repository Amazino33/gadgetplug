<?php

namespace App\Models\Assistant;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The log line for one question: who asked, on which channel, what it cost,
 * how long it took and how it ended. Also what the daily cap counts.
 */
class AssistantQuestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_UNANSWERED = 'unanswered';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_ERROR = 'error';

    public const STATUS_CAPPED = 'capped';

    public const STATUS_NO_ACCESS = 'no_access';

    protected $guarded = [];

    protected $casts = [
        'tools_called' => 'array',
        'counts_toward_cap' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AssistantToolCall::class, 'question_id');
    }
}
