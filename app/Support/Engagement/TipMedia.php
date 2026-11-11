<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/**
 * Video and GIF for tips and Learn cards, sized for mobile data.
 *
 * MP4 up to 5 MB, GIF up to 3 MB. A poster is always shown first and the
 * clip only loads once it is on screen. A GIF's poster is made from its first
 * frame (Intervention is already installed); there is no FFmpeg on the host,
 * so an MP4's poster has to be uploaded with it.
 */
final class TipMedia
{
    public const MP4_MAX_KB = 5 * 1024;

    public const GIF_MAX_KB = 3 * 1024;

    /** Why this upload is refused, or null if it is fine. */
    public static function videoProblem(?string $mimeType, int $bytes): ?string
    {
        return match ($mimeType) {
            'video/mp4' => $bytes > self::MP4_MAX_KB * 1024 ? 'MP4 videos must be 5 MB or smaller. Most vendors are on mobile data.' : null,
            'image/gif' => $bytes > self::GIF_MAX_KB * 1024 ? 'GIFs must be 3 MB or smaller. Most vendors are on mobile data.' : null,
            default => 'Upload an MP4 video or a GIF.',
        };
    }

    /**
     * Whether the video field (as the admin form holds it) is an MP4: a fresh
     * upload is checked directly, an already-saved one through the record.
     */
    public static function stateHasMp4(mixed $state, ?HasMedia $record, string $videoCollection = 'video'): bool
    {
        foreach ((array) $state as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                return $file->getMimeType() === 'video/mp4';
            }
        }

        return filled($state) && $record?->getFirstMedia($videoCollection)?->mime_type === 'video/mp4';
    }

    /**
     * What a tip or Learn card needs to draw its media, or null for none.
     *
     * @return array{url: string, kind: 'mp4'|'gif', poster: string|null}|null
     */
    public static function video(HasMedia $model, string $videoCollection = 'video', string $posterCollection = 'poster'): ?array
    {
        $video = $model->getFirstMedia($videoCollection);

        if (! $video) {
            return null;
        }

        return [
            'url' => $video->getUrl(),
            'kind' => $video->mime_type === 'image/gif' ? 'gif' : 'mp4',
            'poster' => $model->getFirstMediaUrl($posterCollection) ?: null,
        ];
    }

    /**
     * Give a GIF without a poster one made from its first frame. Quiet on
     * failure: the card then shows the GIF behind a play button instead.
     */
    public static function ensureGifPoster(HasMedia $model, string $videoCollection = 'video', string $posterCollection = 'poster'): void
    {
        $video = $model->getFirstMedia($videoCollection);

        if (! $video || $video->mime_type !== 'image/gif' || $model->getFirstMedia($posterCollection)) {
            return;
        }

        try {
            $jpeg = (string) (new ImageManager(new Driver))
                ->decodePath($video->getPath())
                ->scaleDown(800, 800)
                ->encode(new JpegEncoder(quality: 75));

            $model->addMediaFromString($jpeg)
                ->usingFileName('poster-'.$video->id.'.jpg')
                ->toMediaCollection($posterCollection);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
