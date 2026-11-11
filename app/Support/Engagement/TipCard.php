<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;

/**
 * Everything the tip card view needs, from a saved tip or from the admin
 * form's unsaved state. One shape, so the live preview and the real pop-up
 * draw the same card.
 */
final class TipCard
{
    /**
     * @return array{title: string, messageHtml: string, buttonLabel: string|null, hasButton: bool, imageUrl: string|null, video: array|null, style: string, isAnnouncement: bool, showNotNow: bool, delay: int}
     */
    public static function fromTip(Tip $tip, bool $hasButton): array
    {
        return [
            'title' => (string) $tip->title,
            'messageHtml' => TipMessage::html($tip->message),
            'buttonLabel' => $tip->button_label ?: null,
            'hasButton' => $hasButton,
            'imageUrl' => $tip->getFirstMediaUrl(Tip::IMAGE_COLLECTION) ?: null,
            'video' => TipMedia::video($tip, Tip::VIDEO_COLLECTION, Tip::POSTER_COLLECTION),
            'style' => $tip->display_style ?: ($tip->isAnnouncement() ? Tip::STYLE_MODAL : Tip::STYLE_SHEET),
            'isAnnouncement' => $tip->isAnnouncement(),
            'showNotNow' => (bool) $tip->show_not_now,
            'delay' => max(1, (int) $tip->delay_seconds),
        ];
    }

    /**
     * From the admin form while it is being edited. Media is whatever the
     * saved record already has; new uploads appear once saved.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromState(array $state, ?Tip $record = null): array
    {
        $isAnnouncement = ($state['type'] ?? Tip::TYPE_TIP) === Tip::TYPE_ANNOUNCEMENT;

        return [
            'title' => filled($state['title'] ?? null) ? (string) $state['title'] : 'Did you know…?',
            'messageHtml' => TipMessage::html(is_string($state['message'] ?? null) ? $state['message'] : null),
            'buttonLabel' => filled($state['button_label'] ?? null) ? (string) $state['button_label'] : null,
            'hasButton' => filled($state['button_label'] ?? null),
            'imageUrl' => $record?->getFirstMediaUrl(Tip::IMAGE_COLLECTION) ?: null,
            'video' => $record ? TipMedia::video($record, Tip::VIDEO_COLLECTION, Tip::POSTER_COLLECTION) : null,
            'style' => $state['display_style'] ?? ($isAnnouncement ? Tip::STYLE_MODAL : Tip::STYLE_SHEET),
            'isAnnouncement' => $isAnnouncement,
            'showNotNow' => (bool) ($state['show_not_now'] ?? true),
            'delay' => max(1, (int) ($state['delay_seconds'] ?? 4)),
        ];
    }
}
