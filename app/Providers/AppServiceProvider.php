<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiter guarding the agent API.
     *
     * `api/*` is the one surface a stranger can reach that inspects a credential, and unlike the
     * login form it has no session and no CSRF token to slow anybody down. Every other route here
     * that reads a secret is throttled — invitations at 6 a minute, passkeys at 10, agent credentials
     * at 6 — and this one was not, which is what a production check found.
     *
     * **The risk is the worker pool, not the token.** 48 characters of base62 is not brute-forceable,
     * so nobody is guessing their way in. What an unlimited endpoint offers is a database query per
     * request with no ceiling, against a PHP-FPM pool of ten children.
     *
     * Hence **two** limits rather than one, because either alone is porous:
     *
     * - **by address**, which is what actually stops the flood. A caller rotating a made-up token on
     *   every request has a different token bucket each time, so a per-token limit would never see
     *   the same key twice;
     * - **by token**, so one misbehaving agent — a polling loop with no backoff — cannot spend the
     *   whole address budget of a NAT it shares with well-behaved ones. The token is hashed into the
     *   key rather than used raw, so the cache never holds a usable credential.
     *
     * The numbers are deliberately generous: an agent submitting orders should never come near them,
     * and they exist to bound abuse rather than to pace legitimate traffic. Revisit them once there
     * are real agents to measure, which there are not yet.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('agent', fn (Request $request): array => [
            Limit::perMinute(300)->by('agent-ip:'.$request->ip()),
            Limit::perMinute(120)->by('agent-token:'.hash('sha256', (string) $request->bearerToken())),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
