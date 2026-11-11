<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Pages\Concerns;

use App\Models\Tip;
use App\Support\Engagement\TipMedia;

/**
 * The form's "Goes to" choice is not a column: it decides which of
 * button_route / button_url is kept, so a tip never carries a stale second
 * destination. After saving, a GIF without a poster gets one.
 */
trait NormalisesTipButton
{
    /** @param  array<string, mixed>  $data */
    protected function normaliseButton(array $data): array
    {
        $target = $data['button_target'] ?? 'feature';
        unset($data['button_target']);

        if ($target !== 'page') {
            $data['button_route'] = null;
            $data['button_params'] = null;
        }

        if ($target !== 'url') {
            $data['button_url'] = null;
        }

        return $data;
    }

    protected function makeGifPoster(): void
    {
        /** @var Tip $tip */
        $tip = $this->getRecord();

        TipMedia::ensureGifPoster($tip, Tip::VIDEO_COLLECTION, Tip::POSTER_COLLECTION);
    }
}
