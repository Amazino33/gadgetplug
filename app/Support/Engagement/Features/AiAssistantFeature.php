<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantAccess;
use Illuminate\Support\Facades\DB;

/**
 * Ask GadgetPlug. It is a chat bubble on every page, not a page, so "Show me"
 * fires a browser event instead of navigating, and the bubble opens. That one
 * listener is the only change to the assistant.
 */
class AiAssistantFeature extends Feature
{
    public function key(): string
    {
        return 'ai_assistant';
    }

    public function defaultTitle(): string
    {
        return 'Ask GadgetPlug';
    }

    public function defaultDescription(): string
    {
        return 'Tap the chat bubble at the bottom of any page and ask about your sales, stock or debts in plain English.';
    }

    /** Heard by the chat bubble (one x-on line in its markup), which opens. */
    public function clientEvent(): ?string
    {
        return 'gp-open-assistant';
    }

    public function canAccess(User $user, Vendor $vendor): bool
    {
        return AssistantAccess::isEnabledFor($user, $vendor);
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => DB::table('assistant_messages')
                    ->join('assistant_threads', 'assistant_threads.id', '=', 'assistant_messages.thread_id')
                    ->where('assistant_messages.role', 'user'),
                vendorColumn: 'assistant_threads.vendor_id',
                usedAtColumn: 'assistant_messages.created_at',
                userColumns: ['assistant_messages.user_id' => 'assistant_messages.created_at'],
            ),
        ];
    }
}
