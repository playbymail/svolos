<?php

use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\GameSeatController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationAcceptanceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('docs', 'Docs')->name('docs');

/*
 * Accepting an invitation is the only way an account is created, and it is guest-only: it creates a
 * new account and signs it in, so an authenticated visitor is on the wrong screen and `guest` sends
 * them to their dashboard instead of letting them consume the invitation from inside their session.
 *
 * The `{token}` is the plain-text token from the emailed link, which is looked up by hash — it is not
 * a route key, so there is no model binding here. The POST is throttled because the token is the only
 * credential the route has, and 6 attempts a minute is far below any useful guessing rate.
 */
Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
        ->name('invitations.show');

    Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitations.store');
});

/*
 * The member-facing home. It is a real controller rather than `Route::inertia()` because it reads the
 * signed-in account's seats, and it stays named `dashboard` because several redirects point at it —
 * including the one that lands an administrator inside an impersonated session.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

/*
 * Ending an impersonation is the one half of the feature that must **not** be in the admin area.
 * The session making this request is the account being impersonated — a member — so `admin` would
 * refuse the request that ends the impersonation, and `verified` would strand an administrator who
 * impersonated an account that has not verified its address. `auth` alone is the whole gate: the
 * only thing the route does is read an id the session already holds and put that account back.
 *
 * Starting is the mirror image and lives in the admin group below, as `admin.users.impersonate`.
 */
Route::delete('impersonate', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonation.stop');

/*
 * The administration area. `admin` has to come after `auth` so a guest is redirected to the login
 * page instead of being shown a 403 that confirms the route exists; a signed-in member gets the
 * 403. Every route added here must stay inside this group — AdminAccessTest sweeps the route
 * collection and fails if a route named `admin.*` is missing any of the three.
 */
Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::inertia('/', 'admin/Index')->name('index');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

        Route::get('games', [GameController::class, 'index'])->name('games.index');
        Route::post('games', [GameController::class, 'store'])->name('games.store');
        Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
        Route::put('games/{game}', [GameController::class, 'update'])->name('games.update');
        Route::delete('games/{game}', [GameController::class, 'destroy'])->name('games.destroy');

        /*
         * Seat routes are nested under their game and **scoped** to it. `scopeBindings()` makes `{seat}`
         * resolve through `Game::seats()` instead of globally, so a seat id from another game 404s here
         * rather than being edited through the wrong game's URL — an administrator who mistypes a URL
         * must not be able to reach a roster they were not looking at. Without it, both ids bind
         * independently and the mismatch goes unnoticed.
         *
         * There is deliberately **no destroy route**. A seat is retired (`is_active = false`), never
         * deleted, because engine history keeps referring to it; `retire` is the delete button and
         * `reactivate` is why keeping the row is worth it. See `.ai/rules/games.md`.
         */
        Route::scopeBindings()->group(function () {
            Route::post('games/{game}/seats', [GameSeatController::class, 'store'])->name('games.seats.store');
            Route::put('games/{game}/seats/{seat}/role', [GameSeatController::class, 'updateRole'])->name('games.seats.role.update');
            Route::put('games/{game}/seats/{seat}/retire', [GameSeatController::class, 'retire'])->name('games.seats.retire');
            Route::put('games/{game}/seats/{seat}/reactivate', [GameSeatController::class, 'reactivate'])->name('games.seats.reactivate');
        });

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        /*
         * Starting an impersonation. Only an administrator can, only a member can be the target, and
         * `ImpersonationController::store()` is where both are decided. Stopping is deliberately
         * *not* here — see `impersonation.stop` above.
         */
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])->name('users.impersonate');

        /*
         * Sessions are **not** addressed by a route parameter. A `sessions.id` is the live value in
         * that browser's session cookie, so a URL carrying one would put a working impersonation
         * credential into browser history, server logs and referrer headers. The sign-out endpoint
         * therefore takes a sha256 `digest` in the request body and resolves it with
         * `Session::findByDigest()`. See `.ai/rules/sessions.md`.
         */
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('sessions', [SessionController::class, 'destroy'])->name('sessions.destroy');
    });

require __DIR__.'/settings.php';
