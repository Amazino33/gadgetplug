<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\Vendor;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\LoginResponse::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Sign in with an email, as always — or with a free shop's WhatsApp
        // number, because a free shop may register without an email at all.
        Fortify::authenticateUsing(function (Request $request) {
            $login    = trim((string) $request->input(Fortify::username()));
            $password = (string) $request->input('password');
            $provider = Auth::guard(config('fortify.guard'))->getProvider();

            if (str_contains($login, '@')) {
                $user = $provider->retrieveByCredentials(['email' => Str::lower($login)]);
            } else {
                // Only free shops sign in by number. users.phone is not unique
                // (customers type theirs at checkout); a free shop's
                // WhatsApp number is, across every vendor.
                $number = WhatsAppNumber::normalise($login);
                $user   = $number
                    ? Vendor::where('plan', Vendor::PLAN_FREE)->where('whatsapp', $number)->first()?->user
                    : null;
            }

            if (! $user || ! $provider->validateCredentials($user, ['password' => $password])) {
                return null;
            }

            $provider->rehashPasswordIfRequired($user, ['password' => $password]);

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // /become-a-vendor. Named limiters rather than throttle:5,60 — the
        // unnamed form keys on the IP alone, so the link check's 30 a minute
        // would have used up the signup's 5 an hour within seconds.
        RateLimiter::for('free-shop-signup', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('free-shop-slug-check', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
