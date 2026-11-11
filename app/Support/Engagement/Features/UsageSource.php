<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * One table that shows a feature has been used.
 *
 * "First use" is the earliest row in the source for a vendor and, where the
 * table records who did it, for a person. Every question the app asks about
 * usage — hide a tip, tick a Learn card, count a tip's adoptions, list vendors
 * not using something — runs through these same few columns, so there is one
 * definition of "used it" per feature and not four.
 */
final class UsageSource
{
    /**
     * @param  Closure(): (Builder|EloquentBuilder)  $query  Rows that count as use.
     * @param  array<string, string>  $userColumns  user column => the timestamp that user's use happened at.
     *                                              Empty when the table does not record who did it.
     * @param  class-string|null  $model  Saving one of these refreshes the vendor's usage cache.
     */
    public function __construct(
        public readonly Closure $query,
        public readonly string $vendorColumn = 'vendor_id',
        public readonly string $usedAtColumn = 'created_at',
        public readonly array $userColumns = [],
        public readonly ?string $model = null,
    ) {}

    /** A fresh base query each time: builders are mutable. */
    public function newQuery(): Builder
    {
        $query = ($this->query)();

        return $query instanceof EloquentBuilder ? $query->toBase() : $query;
    }

    public function recordsUser(): bool
    {
        return $this->userColumns !== [];
    }
}
