<?php

declare(strict_types=1);

namespace App\Enums\Assistant;

/**
 * How one question went. Stored on the assistant's reply
 * (assistant_messages.status); the unanswered ones are also copied to
 * assistant_unanswered_questions for review.
 */
enum AnswerStatus: string
{
    /** Answered from a tool's figures, or a plain reply that needed none. */
    case Answered = 'answered';

    /** A tool ran and honestly found nothing (has_data: false). */
    case NoData = 'no_data';

    /** The asker may not see that figure; refused without hinting at it. */
    case NoAccess = 'no_access';

    /** No tool covers the question ("I can't check that yet"). */
    case CannotAnswer = 'cannot_answer';

    /** Every tool call the model made failed. */
    case ToolError = 'tool_error';

    /** The figures were fetched but the reply could not be phrased (second call failed, empty, too long or raw JSON). */
    case PhrasingFailed = 'phrasing_failed';

    /** The model provider failed: out of credit, rate limited, timed out, or errored. */
    case ProviderError = 'provider_error';

    /** Something on our side failed outside the model call. */
    case InternalError = 'internal_error';

    /** A daily cap stopped the question before the model was called. */
    case CapReached = 'cap_reached';

    /** The store is brand new, so the question was answered without the model. */
    case NewStore = 'new_store';

    /** The asker has several stores and has not picked one yet. */
    case NeedsStore = 'needs_store';

    /** The asker has no store in this business. */
    case NoStores = 'no_stores';

    /** Kept for review: the assistant should have answered and did not. */
    public function isUnanswered(): bool
    {
        return in_array($this, [self::CannotAnswer, self::ToolError, self::PhrasingFailed, self::ProviderError, self::InternalError], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Answered => 'Answered',
            self::NoData => 'No data',
            self::NoAccess => 'No access',
            self::CannotAnswer => 'No tool for it',
            self::ToolError => 'Tool failed',
            self::PhrasingFailed => 'Reply failed',
            self::ProviderError => 'Provider failed',
            self::InternalError => 'Internal error',
            self::CapReached => 'Cap reached',
            self::NewStore => 'New store',
            self::NeedsStore => 'Store not picked',
            self::NoStores => 'No stores',
        };
    }

    /** @return array<string, string> value => label, for filters */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
