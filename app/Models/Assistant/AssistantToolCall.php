<?php

namespace App\Models\Assistant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tool the model asked to run, what it was given, and how it went. */
class AssistantToolCall extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_DENIED = 'denied';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNKNOWN_TOOL = 'unknown_tool';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'dropped_input' => 'array',
        'truncated' => 'boolean',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssistantQuestion::class, 'question_id');
    }
}
