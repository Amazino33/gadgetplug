<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Where a reader has got to in the feed.
 *
 * Carries the exact keyset — bucket, publication time, id — plus the session's
 * starting bucket and whether the read has already wrapped past it. The seed
 * travels in the cursor rather than the session so a page request is
 * self-describing: the same cursor always yields the same next page, which is
 * what makes the endpoint cacheable and the behaviour reproducible in a test.
 *
 * Encrypted rather than base64: an opaque cursor cannot be hand-edited into
 * scanning the catalogue in an order the feed never intended, and a tampered
 * one is treated as no cursor at all rather than as an error page.
 */
class FeedCursor
{
    /** A fresh reader, starting at their session's bucket. */
    public static function start(int $seedBucket): array
    {
        return [
            'bucket'       => $seedBucket,
            // Far future, so the first read takes the newest post in the bucket
            // rather than excluding it on a "published before" comparison.
            'published_at' => CarbonImmutable::now()->addCentury()->toDateTimeString(),
            'id'           => PHP_INT_MAX,
            'seed'         => $seedBucket,
            'wrapped'      => false,
        ];
    }

    /** The point where a reader crosses from the end of the list back to 0. */
    public static function wrapPoint(int $seedBucket): array
    {
        return [
            'bucket'       => -1,
            'published_at' => CarbonImmutable::now()->addCentury()->toDateTimeString(),
            'id'           => PHP_INT_MAX,
            'seed'         => $seedBucket,
            'wrapped'      => true,
        ];
    }

    public static function encode(Product $last, int $seedBucket): string
    {
        return Crypt::encryptString(json_encode([
            'bucket'       => (int) $last->feed_bucket,
            'published_at' => optional($last->published_at)->toDateTimeString()
                ?? CarbonImmutable::now()->toDateTimeString(),
            'id'           => (int) $last->id,
            'seed'         => $seedBucket,
            // Once past the seed on the wrapped pass, stay wrapped — otherwise
            // the reader would loop the catalogue forever.
            'wrapped'      => (int) $last->feed_bucket < $seedBucket,
        ]));
    }

    /** Null for anything unreadable, so a bad cursor restarts rather than 500s. */
    public static function decode(?string $cursor): ?array
    {
        if (! $cursor) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($cursor), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! isset($data['bucket'], $data['id'], $data['seed'])) {
            return null;
        }

        return [
            'bucket'       => (int) $data['bucket'],
            'published_at' => $data['published_at'] ?? CarbonImmutable::now()->toDateTimeString(),
            'id'           => (int) $data['id'],
            'seed'         => (int) $data['seed'],
            'wrapped'      => (bool) ($data['wrapped'] ?? false),
        ];
    }

    /** A per-session starting bucket — what makes the feed differ between visits. */
    public static function seedForSession(): int
    {
        return (int) session()->remember('feed.seed_bucket', fn () => random_int(0, 99));
    }
}
