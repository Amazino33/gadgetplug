<?php

use App\Http\Middleware\TrackAffiliateEngagement;
use App\Jobs\ClearAffiliateHoldsJob;
use App\Jobs\DemoteInactiveAffiliatesJob;
use App\Jobs\ReleaseStaleReservationsJob;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Appended so it runs after the session is started and sees the final
        // response — it counts pages a referred visitor actually landed on,
        // which is what the engaged-visit reward is paid against.
        $middleware->web(append: [TrackAffiliateEngagement::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Hourly rather than daily since the hold is measured in days; this keeps
        // a commission from sitting cleared-but-uncredited for up to a day longer
        // than its actual hold window.
        $schedule->job(new ClearAffiliateHoldsJob())->hourly();

        // Daily, not hourly — a 21-day inactivity window doesn't need
        // hourly granularity the way a multi-day hold does.
        $schedule->job(new DemoteInactiveAffiliatesJob())->daily();

        // Ticks hourly and lets each vendor's own reminder_frequency decide whether
        // it is actually due, so per-store cadence and quiet hours are data rather
        // than schedule definitions. withoutOverlapping guards the shared host:
        // a run that stalls on a slow WhatsApp API must not stack up behind itself.
        $schedule->command('storekeeper:remind')
            ->hourly()
            ->withoutOverlapping();

        // Also hourly, for the same reason: each vendor's daily_summary_time
        // decides whether its summary is due. An hourly tick that mostly does
        // nothing is the cost of letting every store pick its own hour without
        // a schedule entry per store.
        $schedule->command('vendor:daily-summary')
            ->hourly()
            ->withoutOverlapping();

        // Hourly for the same reason as the affiliate hold above: the 24h
        // staleness window doesn't need finer granularity, but a reservation
        // sitting stuck for up to a day longer than that window blocks real
        // stock from real buyers the whole time it waits.
        $schedule->job(new ReleaseStaleReservationsJob())->hourly();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Both handlers below exist for the same reason: an error page the user
        // cannot act on is worse than useless. A 403 and a 419 are each a dead
        // end that tells someone what went wrong but not what to do about it,
        // and both land most often on people who simply need to sign in.

        // Sends someone to the sign-in form, remembering where they were headed
        // so they arrive there once they are in — a 403 on /plug becomes "log
        // in, then land on /plug" rather than a wall.
        $toLogin = function (Request $request, string $message): ?RedirectResponse {
            $login = rescue(fn () => route('login'), null, false);

            if (blank($login)) {
                return null;
            }

            // Only for page loads. Remembering a POST url would replay the
            // submission after login, which is not what anyone wants.
            if ($request->isMethod('GET') && rtrim($request->url(), '/') !== rtrim($login, '/')) {
                session()->put('url.intended', $request->fullUrl());
            }

            session()->flash('status', $message);

            return new RedirectResponse($login);
        };

        // 419: the session died between opening a form and submitting it. The
        // default "Page Expired" page is at its worst on the login form itself,
        // where it strands someone who was already trying to sign in.
        $exceptions->render(function (Throwable $e, Request $request) use ($toLogin): ?RedirectResponse {
            $isExpired = $e instanceof TokenMismatchException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419);

            if (! $isExpired || $request->expectsJson()) {
                return null;
            }

            return $toLogin($request, 'Your session timed out before that went through — please sign in again.');
        });

        // 403: never a dead end. Where the user goes depends on what they can
        // actually reach from here.
        $exceptions->render(function (Throwable $e, Request $request) use ($toLogin): ?RedirectResponse {
            $isForbidden = $e instanceof AuthorizationException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403);

            if (! $isForbidden) {
                return null;
            }

            // Only real page loads. Livewire and JSON callers can't follow a 302
            // and surface the failure themselves.
            if (! $request->isMethod('GET') || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            $panel = Filament::getCurrentPanel();
            $signedIn = $panel ? $panel->auth()->check() : auth()->check();

            // Nobody is signed in. This is the common case behind a bare 403 on
            // /plug: the vendor panel has no login route of its own, so Filament
            // has nowhere to bounce an anonymous visitor and throws instead.
            if (! $signedIn) {
                return $toLogin($request, 'Please sign in to continue.');
            }

            $home = $panel ? rescue(fn () => $panel->getUrl(Filament::getTenant()), null, false) : null;

            // Signed in, and the panel has somewhere better to put them — a
            // stale link or bookmark pointing at a page their role no longer
            // covers goes back to the panel dashboard.
            if (filled($home) && rtrim($request->url(), '/') !== rtrim($home, '/')) {
                Notification::make()
                    ->title('You do not have access to that page')
                    ->warning()
                    ->send();

                // Built directly rather than via redirect(), whose helper resolves to
                // Livewire's fluent redirector and doesn't return a RedirectResponse.
                return new RedirectResponse($home);
            }

            // Signed in, but this panel itself is what was refused — a customer
            // opening /plug, say. Redirecting into the panel would loop, so land
            // them on their account instead.
            $account = rescue(fn () => route('account.profile'), null, false);

            if (filled($account) && rtrim($request->url(), '/') !== rtrim($account, '/')) {
                session()->flash('status', 'That area is for store owners. Here is your account instead.');

                return new RedirectResponse($account);
            }

            return null;
        });
    })->create();
