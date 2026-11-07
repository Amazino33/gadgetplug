<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AssistantConversation;
use App\Models\Assistant\AssistantQuestion;

/** What one question produced, for whichever channel asked it. */
final class AssistantReply
{
    public function __construct(
        public readonly string $text,
        public readonly string $status,
        public readonly ?AssistantConversation $conversation = null,
        public readonly ?AssistantQuestion $question = null,
    ) {}

    public function answered(): bool
    {
        return $this->status === AssistantQuestion::STATUS_ANSWERED;
    }
}
