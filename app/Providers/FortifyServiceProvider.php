<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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

        $this->refuseAgentSignIn();
    }

    /**
     * Refuse the login form to an agent account, whatever it posts.
     *
     * Agents authenticate by bearer token against `api/*` and have no business on the sign-in form.
     * They already hold an unusable 64-character password nobody has ever seen, so this callback is
     * not what stops a guess — it is what makes the refusal a *rule* rather than a lucky consequence
     * of the password being long. See `.ai/rules/agents.md`, which lists this as one of the four
     * places a person could otherwise reach an agent account.
     *
     * The credentials are checked **first**, by the framework, and the account is only then rejected.
     * That ordering is the point: a wrong password and a correct password on an agent account fail
     * identically, so the form cannot be used to work out which addresses belong to agents.
     * `Auth::validate()` is what does the checking, so the hasher, the `Validated` event and the
     * timebox that guards against user enumeration all stay the framework's business rather than
     * being reimplemented here.
     *
     * One known consequence: `Auth::validate()` does not rehash a password whose hashing cost is out
     * of date, which `Auth::attempt()` would have done. Nothing in this application changes the
     * hashing configuration, so the cost never goes stale — but if it ever does, this is why old
     * hashes stop being upgraded at sign-in.
     */
    private function refuseAgentSignIn(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $username = (string) $request->input(Fortify::username());

            if (! Auth::validate([Fortify::username() => $username, 'password' => $request->input('password')])) {
                return null;
            }

            $user = User::query()->where(Fortify::username(), $username)->first();

            return $user instanceof User && ! $user->isAgent() ? $user : null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
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

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
