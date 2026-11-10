<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assistant\AnswerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question the assistant should have answered and did not, kept for the
 * platform admin to review (Admin → Assistant → Unanswered).
 */
class AssistantUnansweredQuestion extends Model
{
    protected $fillable = [
        'vendor_id', 'user_id', 'thread_id', 'answer_message_id', 'question', 'answer',
        'reason', 'detail', 'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected $casts = [
        'reason' => AnswerStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AssistantThread::class, 'thread_id');
    }

    public function answerMessage(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'answer_message_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
