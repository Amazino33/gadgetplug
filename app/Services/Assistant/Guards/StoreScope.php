<?php

namespace App\Services\Assistant\Guards;

use App\Models\Store;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Tools\ToolDenied;
use Illuminate\Support\Collection;

/**
 * Turns whatever store the model named into stores the user may actually see.
 *
 * The lookup only ever searches the context's accessible stores, so a store
 * name that belongs to another vendor — or to a branch of this vendor the user
 * is not assigned to — simply isn't found. Both get the same answer, which is
 * the point: "no access" must not confirm that a store exists.
 */
final class StoreScope
{
    /**
     * @param  Collection<int, Store>  $stores
     */
    private function __construct(
        public readonly Collection $stores,
        public readonly bool $isCombined,
        public readonly bool $isWholeBusiness,
    ) {}

    /**
     * @param  string|null  $requested  A store name, "all", or null/blank for every accessible store.
     *
     * @throws ToolDenied
     */
    public static function resolve(AssistantContext $context, ?string $requested): self
    {
        $accessible = $context->stores();

        if ($accessible->isEmpty()) {
            throw ToolDenied::noAccess('You are not assigned to any store yet.');
        }

        $requested = self::normalize($requested);

        if ($requested === '' || in_array($requested, ['all', 'all stores', 'every store', 'whole business', 'everything'], true)) {
            return new self($accessible, $accessible->count() > 1, $context->seesWholeBusiness());
        }

        $match = $accessible->first(fn (Store $store) => self::normalize($store->name) === $requested)
            ?? self::uniquePartialMatch($accessible, $requested);

        if (! $match) {
            throw ToolDenied::noAccess("You don't have access to a store called \"{$requested}\".");
        }

        // One store out of one is still the whole business for a single-shop vendor.
        $whole = $accessible->count() === 1 && $context->seesWholeBusiness();

        return new self(collect([$match]), false, $whole);
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return $this->stores->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The scope as the answer must state it. Uses the panel's own wording for
     * a combined figure, so the chat and the dashboard never disagree about
     * what "all" covered.
     */
    public function label(): string
    {
        if ($this->isWholeBusiness && $this->isCombined) {
            return 'Whole business — all '.$this->stores->count().' stores';
        }

        if ($this->isWholeBusiness) {
            return 'Whole business — '.$this->stores->first()->name;
        }

        if ($this->isCombined) {
            return 'Your stores combined: '.$this->stores->pluck('name')->implode(', ');
        }

        return $this->stores->first()->name;
    }

    /** Lowercased, trimmed, inner whitespace collapsed. */
    public static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * "Ikeja" for "Ikeja Branch" — but only when exactly one accessible store
     * contains it. Two candidates is ambiguous and resolves to nothing rather
     * than to a guess.
     */
    private static function uniquePartialMatch(Collection $stores, string $requested): ?Store
    {
        if (mb_strlen($requested) < 3) {
            return null;
        }

        $hits = $stores->filter(fn (Store $store) => str_contains(self::normalize($store->name), $requested));

        return $hits->count() === 1 ? $hits->first() : null;
    }
}
