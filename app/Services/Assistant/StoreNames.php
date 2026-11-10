<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Exceptions\Assistant\InvalidScope;
use App\Models\Store;

/**
 * Which stores a tool call is about, from the names the model uses.
 *
 * The prompt lists the asker's stores by name, so the tools take names too:
 * `store` (one name), `stores` (several names, or "all"). Each is looked up
 * among the asker's own stores only (AssistantScope), ignoring case and
 * spacing; a short name that fits exactly one store ("Itel" for "Itel Home")
 * is taken. With neither, the active store.
 *
 * Why: live on 2026-11-10 the tools took store IDs while the prompt gave
 * names, the model sent store_id 3 for "Itel Home" (store 6; there is no
 * store 3), the tool was refused, and the user was told "I can't check that
 * yet". The model now never has to know a number.
 *
 * store_id / store_ids are still read when a caller sends them (older
 * clients, tests); the model is not offered them, and the query checks them
 * against the asker's access as before.
 */
final class StoreNames
{
    /**
     * @param  array<string, mixed>  $args  the tool call's arguments
     * @return array{store_id?: int, store_ids?: array<int>}
     *
     * @throws InvalidScope when a name matches none, or several, of the asker's stores
     */
    public static function scopeFor(array $args, ?Store $activeStore, AssistantScope $access): array
    {
        $names = [];

        if (isset($args['stores']) && is_array($args['stores'])) {
            $names = array_values(array_filter(array_map(fn ($n) => is_scalar($n) ? trim((string) $n) : '', $args['stores'])));
        } elseif (isset($args['store']) && is_scalar($args['store']) && trim((string) $args['store']) !== '') {
            $names = [trim((string) $args['store'])];
        }

        if ($names !== []) {
            $ids = self::resolve($names, $access);

            return count($ids) === 1 ? ['store_id' => $ids[0]] : ['store_ids' => $ids];
        }

        if (isset($args['store_ids']) && is_array($args['store_ids'])) {
            return ['store_ids' => $args['store_ids']];
        }

        if (isset($args['store_id'])) {
            return ['store_id' => (int) $args['store_id']];
        }

        return $activeStore !== null ? ['store_id' => (int) $activeStore->id] : [];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     *
     * @throws InvalidScope
     */
    private static function resolve(array $names, AssistantScope $access): array
    {
        $stores = $access->stores();
        $ids = [];

        foreach ($names as $name) {
            $wanted = self::normalize($name);

            if (in_array($wanted, ['all', 'all stores', 'every store', 'whole business'], true)) {
                return $stores->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            }

            $exact = $stores->filter(fn (Store $s) => self::normalize($s->name) === $wanted);
            $match = $exact->isNotEmpty() ? $exact : $stores->filter(fn (Store $s) => $wanted !== '' && str_contains(self::normalize($s->name), $wanted));

            if ($match->count() !== 1) {
                $list = $stores->pluck('name')->map(fn ($n) => '"'.$n.'"')->implode(', ');

                throw new InvalidScope($match->isEmpty()
                    ? "No store named \"{$name}\". Stores: {$list}, or \"all\"."
                    : "\"{$name}\" matches more than one store. Stores: {$list}.");
            }

            $ids[] = (int) $match->first()->id;
        }

        return array_values(array_unique($ids));
    }

    private static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace(['"', "'"], '', $name))));
    }
}
