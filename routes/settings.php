<?php

use App\Http\Controllers\Auth\PasskeyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Everything under /settings needs `auth`. Only the destructive and security-sensitive routes
 * also need `verified`: a user who has not clicked the verification link yet still has to be
 * able to read their own profile, correct the email address the link was sent to, and pick a
 * theme, so gating those on verification would lock them out of the fix.
 *
 * The destination of the /settings redirect is a literal path rather than
 * route('profile.edit'): route names are not resolvable while the route files are still being
 * loaded, and a closure route would break `php artisan route:cache`. SettingsRouteTest asserts
 * it lands on the named route so a rename cannot silently break it.
 */
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});

/*
 * Fortify registers passkey registration, listing and deletion but not renaming, so the
 * rename endpoint is added here alongside Fortify's own /user/passkeys routes and with the
 * same auth + password confirmation middleware.
 */
Route::put('user/passkeys/{passkey}', [PasskeyController::class, 'update'])
    ->middleware(['auth', RequirePassword::class, 'throttle:10,1'])
    ->name('passkey.update');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
