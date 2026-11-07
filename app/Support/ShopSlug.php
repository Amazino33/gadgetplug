<?php

namespace App\Support;

use App\Models\Vendor;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The rules for a free shop's link name — the "joy-provisions" in
 * gadgetplug.com.ng/joy-gadgets.
 *
 * The link will be served at the root of the site (Prompt 2), so a slug must
 * never be a word the app itself answers on. Besides the fixed reserved list,
 * every first URL segment of every registered route is refused, read from the
 * router at the time of the check — a page added next month is protected the
 * day it ships without anyone remembering to update a list.
 */
final class ShopSlug
{
    public const MIN = 3;
    public const MAX = 40;

    public const RESERVED = [
        'admin', 'login', 'logout', 'register', 'api', 'dashboard', 'vendor', 'vendors',
        'shop', 's', 'become-a-vendor', 'affiliate', 'settings', 'storage', 'assets',
        'public', 'support', 'help', 'about', 'terms', 'privacy',
        // Livewire v4 serves from a hashed "livewire-xxxx" prefix, which the
        // route scan catches; the bare word is kept off in case that changes.
        'livewire',
    ];

    private const FORMAT = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** A suggestion made from the business name: "Joy's Provisions!" → "joys-provisions". */
    public static function suggest(string $businessName): string
    {
        $slug = Str::slug(str_replace(["'", "\u{2019}"], '', $businessName));

        return trim(Str::limit($slug, self::MAX, ''), '-');
    }

    /**
     * Why this slug cannot be used, or null when it can.
     *
     * @param  int|null  $ignoreVendorId  the shop's own row, when it is editing its application
     */
    public static function problem(?string $slug, ?int $ignoreVendorId = null): ?string
    {
        $slug = (string) $slug;

        if ($slug === '') {
            return 'Choose a shop link.';
        }

        $length = strlen($slug);

        if ($length < self::MIN || $length > self::MAX) {
            return 'Use '.self::MIN.' to '.self::MAX.' characters.';
        }

        if (! preg_match(self::FORMAT, $slug)) {
            return 'Use only lowercase letters, numbers and hyphens, not starting or ending with a hyphen.';
        }

        if (self::isReserved($slug)) {
            return 'That link is reserved. Please choose another.';
        }

        $taken = Vendor::query()
            ->where('slug', $slug)
            ->when($ignoreVendorId, fn ($q) => $q->whereKeyNot($ignoreVendorId))
            ->exists();

        return $taken ? 'That link is already taken.' : null;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true)
            || in_array($slug, self::routeSegments(), true);
    }

    /**
     * The first path segment of every registered route — "plug", "sales-point",
     * "receipt", "livewire" and so on. Parameters ("{product}") are skipped:
     * they are what the shop link itself will be.
     *
     * Read fresh on every call. It is a loop over a few hundred in-memory
     * routes, and caching it would hide a route registered later in the
     * request (or, in tests, by the test itself).
     *
     * @return list<string>
     */
    public static function routeSegments(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $first = strtolower(explode('/', trim($route->uri(), '/'))[0] ?? '');

            if ($first !== '' && ! str_starts_with($first, '{')) {
                $found[$first] = true;
            }
        }

        return array_keys($found);
    }
}
