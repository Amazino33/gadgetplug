<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantQueryRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'query_key',
        'scope_json',
        'date_from',
        'date_to',
        'result_json',
        'success',
        'has_data',
        'failure_reason',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'scope_json' => 'array',
        'result_json' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
        'success' => 'boolean',
        'has_data' => 'boolean',
        'duration_ms' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'message_id');
    }
}
