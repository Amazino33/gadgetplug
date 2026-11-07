<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class SetThreadStoreAction
{
    /**
     * Resolves a store by exact match first, then by case-insensitive partial match across the user's owned stores.
     *
     * @return array{store: ?Store, error: ?string}
     */
    public function resolveStoreByName(int $userId, string $name): array
    {
        $target = trim($name);
        if ($target === '') {
            return ['store' => null, 'error' => 'No store with that exact name, or multiple matches.'];
        }

        $baseQuery = Store::query()
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $userId));

        // 1. Exact match first
        $exactMatches = (clone $baseQuery)
            ->where('name', $target)
            ->get();

        if ($exactMatches->count() === 1) {
            return ['store' => $exactMatches->first(), 'error' => null];
        }

        if ($exactMatches->count() > 1) {
            return ['store' => null, 'error' => 'No store with that exact name, or multiple matches.'];
        }

        // 2. Case-insensitive LIKE %name% search across user's owned stores
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $target);
        $fuzzyMatches = (clone $baseQuery)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($escaped).'%'])
            ->get();

        if ($fuzzyMatches->count() === 1) {
            return ['store' => $fuzzyMatches->first(), 'error' => null];
        }

        return ['store' => null, 'error' => 'No store with that exact name, or multiple matches.'];
    }

    /**
     * Switches thread store by resolving store name.
     */
    public function switchByName(AssistantThread $thread, string $storeName, string $source = 'manual'): ?Store
    {
        $resolved = $this->resolveStoreByName((int) $thread->user_id, $storeName);
        if ($resolved['store'] === null) {
            return null;
        }

        $this->execute($thread, $resolved['store'], $source);

        return $resolved['store'];
    }

    /**
     * @throws UnauthorizedStoreAccess
     */
    public function execute(AssistantThread $thread, Store $store, string $source = 'manual'): void
    {
        $isOwner = Store::query()
            ->where('id', $store->id)
            ->whereHas('vendor', fn ($v) => $v->where('user_id', $thread->user_id))
            ->exists();

        if (! $isOwner) {
            throw new UnauthorizedStoreAccess("Store [{$store->id}] is not owned by the thread owner.");
        }

        DB::transaction(function () use ($thread, $store, $source) {
            $thread->store_id = $store->id;
            $thread->store_removed_at = null;
            $thread->store_removed_name = null;
            $thread->last_message_at = now();
            $thread->save();

            AssistantMessage::create([
                'thread_id' => $thread->id,
                'user_id' => $thread->user_id,
                'store_id' => $store->id,
                'store_name_snapshot' => $store->name,
                'role' => 'system',
                'content' => "[Store context: {$store->name}]",
                'is_prompt' => true,
                'ai_meta' => [
                    'source' => $source,
                ],
            ]);
        });
    }
}
