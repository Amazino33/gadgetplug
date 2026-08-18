<?php

declare(strict_types=1);

namespace App\Services\Import;

use Closure;
use Illuminate\Support\Str;

/**
 * Turns a name into an id once per run, however many rows mention it.
 *
 * A 500-row file naming twelve categories should touch the categories table
 * twelve times, not five hundred - and, more importantly, must not create the
 * same category twice because two rows spelled it differently in case.
 */
class NameResolver
{
    /** @var array<string, int> */
    private array $cache = [];

    /** @param  Closure(string): int  $resolver */
    public function __construct(private readonly Closure $resolver)
    {
    }

    public function resolve(string $name): ?int
    {
        $name = Str::squish($name);

        if ($name === '') {
            return null;
        }

        $key = Str::lower($name);

        return $this->cache[$key] ??= ($this->resolver)($name);
    }
}
