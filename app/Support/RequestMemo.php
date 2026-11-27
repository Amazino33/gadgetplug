<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Answers worked out once per request and then reused: "is this user the
 * super admin?", "does this user own that shop?", "which branches does this
 * shop have?". A panel page asked these a hundred times and more, one query
 * each, and so did every widget loading in the background after it.
 *
 * Registered as a scoped instance: a new, empty memo for every request (and
 * every test), so nothing is ever carried from one request or person to the
 * next. Whatever changes an answer forgets it (see forget()).
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    public static function get(): self
    {
        return app(self::class);
    }

    public function remember(string $key, callable $work): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $work();
        }

        return $this->values[$key];
    }

    /** Forget every answer whose key starts with this. */
    public function forget(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }

    public function flush(): void
    {
        $this->values = [];
    }
}
