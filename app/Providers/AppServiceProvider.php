<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     */
    public function register(): void {
        // One per request (and per queued job), never shared between them.
        // Middleware and controller ask the container for it and get the
        // same object, which is how the middleware's answer reaches them.
        $this->app->scoped(\App\Support\CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        // The reset form lives in the SPA, so the emailed link opens it there.
        ResetPassword::createUrlUsing(
            fn(User $user, string $token) =>
            rtrim(config('app.frontend_url'), '/')
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->email)
        );

        // Each group of public routes counts on its own. A bare throttle:10,1
        // keys only on the IP, so login, sign-up, invitation links and the
        // email link all drew from one shared counter.
        RateLimiter::for('login', fn(Request $request) => [
            // Guessing one account's password from many addresses.
            Limit::perMinute(10)->by('email:' . Str::lower((string) $request->input('email'))),
            // One office behind one address still has room to sign in.
            Limit::perMinute(60)->by('ip:' . $request->ip()),
        ]);
        RateLimiter::for('public', fn(Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('invitation-link', fn(Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('email-link', fn(Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Every invitation is an email from our address. A script must not be
        // able to flood one inbox or use up the daily sending limit for everyone.
        RateLimiter::for('invitations', fn(Request $request) => Limit::perHour(50)->by($request->user()->id));
    }
}
