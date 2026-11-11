<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Moments that fired for a person at a vendor and have not expired yet.
 *
 * Short-lived by design: a tip about credit sales makes sense right after the
 * fifth cash sale, not next week. Kept in the cache, never the database.
 */
class PendingMoments
{
    public function record(int $userId, int $vendorId, string $momentKey, int $value, CarbonInterface $expiresAt): void
    {
        $key = $this->key($userId, $vendorId);
        $pending = $this->unexpired(Cache::get($key, []));

        $pending[$momentKey] = ['value' => $value, 'expires_at' => $expiresAt->getTimestamp()];

        $latest = max(array_column($pending, 'expires_at'));
        Cache::put($key, $pending, max(1, $latest - now()->getTimestamp()));
    }

    /** @return array<string, int> moment key => value */
    public function active(int $userId, int $vendorId): array
    {
        return array_map(
            fn (array $entry) => (int) $entry['value'],
            $this->unexpired(Cache::get($this->key($userId, $vendorId), [])),
        );
    }

    public function forget(int $userId, int $vendorId): void
    {
        Cache::forget($this->key($userId, $vendorId));
    }

    private function unexpired(mixed $pending): array
    {
        $now = now()->getTimestamp();

        return array_filter(
            is_array($pending) ? $pending : [],
            fn ($entry) => is_array($entry) && ($entry['expires_at'] ?? 0) > $now,
        );
    }

    private function key(int $userId, int $vendorId): string
    {
        return "engagement:moments:{$userId}:{$vendorId}";
    }
}
