<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Gives an anonymous visitor a stable identity, so a guest can like a post.
 *
 * A long-lived cookie holding a UUID, and nothing else — it identifies a
 * device, never a person, and carries no personal data. Laravel signs and
 * encrypts it by default, so it cannot be forged into somebody else's token.
 *
 * Issued on the response, but also attached to the request immediately, or the
 * very first like of a session would have no token to write against and would
 * be lost — the cookie only reaches the browser after that response.
 */
class EnsureDeviceToken
{
    public const COOKIE = 'gp_device';

    /** Two years: a returning visitor keeps their likes without an account. */
    private const LIFETIME_MINUTES = 60 * 24 * 730;

    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie(self::COOKIE);
        $issued = false;

        if (! $token || ! Str::isUuid($token)) {
            $token = (string) Str::uuid();
            $issued = true;

            // So THIS request can already use it.
            $request->cookies->set(self::COOKIE, $token);
        }

        $response = $next($request);

        if ($issued && method_exists($response, 'cookie')) {
            $response->cookie(new Cookie(
                name: self::COOKIE,
                value: $token,
                expire: now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }

    /** The current device's token, or null outside a request that has one. */
    public static function current(?Request $request = null): ?string
    {
        $request ??= request();

        $token = $request?->cookie(self::COOKIE);

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }
}
