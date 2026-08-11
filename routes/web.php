<?php

use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

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

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

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
