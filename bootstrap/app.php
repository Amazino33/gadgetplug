<?php

use App\Http\Middleware\TrackAffiliateEngagement;
use App\Jobs\ClearAffiliateHoldsJob;
use App\Jobs\DemoteInactiveAffiliatesJob;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A forbidden page inside a Filament panel sends the user back to that
        // panel's dashboard with an explanation, instead of a dead-end 403 —
        // easy to hit now that page access is permission-driven, since a stale
        // link or bookmark may point somewhere the role no longer covers.
        $exceptions->render(function (Throwable $e, Request $request): ?RedirectResponse {
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

            if (! $panel || ! $panel->auth()->check()) {
                return null;
            }

            $home = rescue(fn () => $panel->getUrl(Filament::getTenant()), null, false);

            // Nowhere to send them, or the dashboard itself is what was refused —
            // let the 403 stand rather than bounce in a loop.
            if (blank($home) || rtrim($request->url(), '/') === rtrim($home, '/')) {
                return null;
            }

            Notification::make()
                ->title('You do not have access to that page')
                ->warning()
                ->send();

            // Built directly rather than via redirect(), whose helper resolves to
            // Livewire's fluent redirector and doesn't return a RedirectResponse.
            return new RedirectResponse($home);
        });
    })->create();
