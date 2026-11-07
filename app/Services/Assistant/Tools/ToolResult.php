<?php

namespace App\Services\Assistant\Tools;

/**
 * What a tool hands back: plain data the model reads, plus a link into the
 * panel so the owner can check the figure against the records themselves.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public ?string $link = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function make(array $data, ?string $link = null): self
    {
        return new self($data, $link);
    }

    /**
     * Every list in the result, at any depth, cut to $cap rows. A cut list
     * gets "<key>_total" (the full count) and "<key>_truncated" beside it, so
     * the answer can say "top 25 of 140" rather than presenting a slice as
     * everything.
     *
     * @return array{0: array<string, mixed>, 1: int, 2: bool} [data, rows, truncated]
     */
    public static function cap(array $data, int $cap): array
    {
        $rows = 0;
        $truncated = false;

        $walk = function (array $node) use (&$walk, $cap, &$rows, &$truncated): array {
            $out = [];

            foreach ($node as $key => $value) {
                if (is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0] ?? null)) {
                    $rows += min(count($value), $cap);

                    if (count($value) > $cap) {
                        $truncated = true;
                        $out[$key.'_total'] = count($value);
                        $out[$key.'_truncated'] = true;
                        $value = array_slice($value, 0, $cap);
                    }

                    $out[$key] = array_map(fn ($row) => is_array($row) ? $walk($row) : $row, $value);

                    continue;
                }

                $out[$key] = is_array($value) ? $walk($value) : $value;
            }

            return $out;
        };

        return [$walk($data), $rows, $truncated];
    }
}
