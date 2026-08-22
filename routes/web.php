<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentCredentialController;
use App\Http\Controllers\Admin\AgentSeatController;
use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\GameSeatController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Gamemaster\GameController as GamemasterGameController;
use App\Http\Controllers\Gamemaster\GameSeatController as GamemasterGameSeatController;
use App\Http\Controllers\Gamemaster\GenerationController;
use App\Http\Controllers\Gamemaster\KitTemplateController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Player\GameController as PlayerGameController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('docs', 'Docs')->name('docs');

/*
 * The player introduction — the game's backstory. It is public and guest-reachable on purpose: it is
 * the thing a first-time visitor is here to read, and it must not sit behind the sign-in that only an
 * invited account can pass. It is a page of its own rather than a band on the landing page because it
 * is a thousand words of prose read end to end, and `/` answers what this application is in a screen.
 *
 * Static copy and no state, so `Route::inertia()` is the whole of it; the text lives in the page
 * component, which is the only copy of it the application ships.
 */
Route::inertia('story', 'Story')->name('story');

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
 * Playing a game from the inside — the counterpart of the gamemaster group below, and a **member**
 * area for the same reasons. `EnsureUserIsPlayer` reads an active `GameRole::Player` seat at the game
 * in the URL and reads nothing else, so a gamemaster, an administrator with no seat and a retired
 * player are all refused equally; the middleware says why each of those is deliberate.
 *
 * There is no index — which games you play is the dashboard, which now links here — and no store or
 * destroy: a player does not create or leave a game, they are seated and retired by somebody running
 * it. The profile is a PUT of its own rather than part of a wider update because it is the only thing
 * on the screen a player writes; everything else there is the game's own state, read-only to them.
 *
 * No `scopeBindings()`, which the seat routes elsewhere all carry: the seat is not in the URL. It is
 * the viewer's own seat at the game, resolved from the session, and there is exactly one of those.
 */
Route::middleware(['auth', 'verified', 'player'])
    ->prefix('games')
    ->name('games.')
    ->group(function () {
        Route::get('{game}', [PlayerGameController::class, 'show'])->name('show');
        Route::put('{game}/profile', [PlayerGameController::class, 'updateProfile'])->name('profile.update');
    });

/*
 * Running a game from the inside. These are **member** routes and must stay out of the admin group:
 * a gamemaster is an ordinary account, and the thing that opens these is an active
 * `GameRole::Gamemaster` seat at the game in the URL, which `EnsureUserIsGamemaster` is the only
 * thing that reads. The gate comes after `auth` for the same reason `admin` does — a guest is
 * redirected to the login page rather than shown a 403 that confirms the game exists — and an
 * administrator holding no seat is refused here, because they have `/admin/games/{game}` instead and
 * an authorisation check reads exactly one of the two role systems. See `.ai/rules/roles.md`.
 *
 * There is no index: which games you run is the dashboard, and no create, update-name or destroy —
 * a gamemaster changes a game's status, never its name or short name.
 */
Route::middleware(['auth', 'verified', 'gamemaster'])
    ->prefix('gamemaster')
    ->name('gamemaster.')
    ->group(function () {
        Route::get('games/{game}', [GamemasterGameController::class, 'show'])->name('games.show');
        Route::put('games/{game}', [GamemasterGameController::class, 'update'])->name('games.update');

        /*
         * The seed is its own endpoint rather than another field on the update above, because it
         * answers to the game's status: it may only be set while the game is in setup, and folding
         * it into the status form would attach that condition to a field nobody touched. A
         * gamemaster may set it on the same terms an administrator can — see
         * `Gamemaster\GameController::updateSeed()`.
         */
        Route::put('games/{game}/seed', [GamemasterGameController::class, 'updateSeed'])->name('games.seed.update');

        /*
         * Building the game's world. `{stage}` binds to the `App\Enums\GenerationStage` backed enum,
         * so an unknown stage 404s without a line of code and the planets stage will add no routes
         * at all — the stages differ in what they generate, not in how they are driven.
         *
         * `restart` is a POST rather than a DELETE even though it destroys everything generated, for
         * the same reason nothing else in this area accepts DELETE: the sweep in
         * `tests/Feature/Gamemaster/GameManagementTest.php` asserts that, and the seat rule it exists
         * for is worth more than the verb.
         */
        Route::post('games/{game}/generation/restart', [GenerationController::class, 'restart'])->name('games.generation.restart');
        Route::post('games/{game}/generation/{stage}', [GenerationController::class, 'store'])->name('games.generation.store');
        Route::post('games/{game}/generation/{stage}/accept', [GenerationController::class, 'accept'])->name('games.generation.accept');

        /*
         * Scoped exactly as the admin seat routes are, and for the same reason: `{seat}` resolves
         * through `Game::seats()`, so a seat id from another game 404s rather than being edited
         * through this game's URL. Without it both ids bind independently and the mismatch is
         * written silently. There is deliberately no destroy route here either.
         */
        Route::scopeBindings()->group(function () {
            Route::post('games/{game}/seats', [GamemasterGameSeatController::class, 'store'])->name('games.seats.store');
            Route::put('games/{game}/seats/{seat}/role', [GamemasterGameSeatController::class, 'updateRole'])->name('games.seats.role.update');
            Route::put('games/{game}/seats/{seat}/retire', [GamemasterGameSeatController::class, 'retire'])->name('games.seats.retire');
            Route::put('games/{game}/seats/{seat}/reactivate', [GamemasterGameSeatController::class, 'reactivate'])->name('games.seats.reactivate');
        });
    });

/*
 * A gamemaster's private library of opening kits.
 *
 * **A second gamemaster group, with a different gate, and the difference is the point.** A kit is
 * what every player in a game begins holding, and it belongs to the person who wrote it rather than
 * to any one game — it is written once and used at as many games as its author likes, which is the
 * whole reason it is worth saving. So there is no `{game}` in these URLs, and
 * `EnsureUserIsGamemaster` cannot serve them: its first check is `abort_unless($game instanceof
 * Game)`, so it would refuse everybody. `runs-a-game` asks the weaker question this area needs —
 * does this account run anything at all — and ownership of a particular kit is checked in
 * `Gamemaster\KitTemplateController` against `user_id`.
 *
 * These are `gamemaster.kit-templates.*`, and `GameManagementTest` sweeps them separately from
 * `gamemaster.games.*` for that reason: the ten-route list, the no-DELETE rule and the
 * `gamemaster`-middleware assertion are all statements about **managing one game**, and none of the
 * three is true here. A kit is a document its author wrote and nothing points at it, so `destroy` is
 * an ordinary `DELETE`.
 *
 * `create` is declared ahead of `{kitTemplate}` so the literal segment wins the match.
 */
Route::middleware(['auth', 'verified', 'runs-a-game'])
    ->prefix('gamemaster')
    ->name('gamemaster.')
    ->group(function () {
        Route::get('kit-templates', [KitTemplateController::class, 'index'])->name('kit-templates.index');
        Route::get('kit-templates/create', [KitTemplateController::class, 'create'])->name('kit-templates.create');
        Route::post('kit-templates', [KitTemplateController::class, 'store'])->name('kit-templates.store');
        Route::get('kit-templates/{kitTemplate}', [KitTemplateController::class, 'show'])->name('kit-templates.show');
        Route::put('kit-templates/{kitTemplate}', [KitTemplateController::class, 'update'])->name('kit-templates.update');
        Route::delete('kit-templates/{kitTemplate}', [KitTemplateController::class, 'destroy'])->name('kit-templates.destroy');
        Route::get('kit-templates/{kitTemplate}/download', [KitTemplateController::class, 'download'])->name('kit-templates.download');
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

        Route::get('games', [GameController::class, 'index'])->name('games.index');
        Route::post('games', [GameController::class, 'store'])->name('games.store');
        Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
        Route::put('games/{game}', [GameController::class, 'update'])->name('games.update');
        Route::delete('games/{game}', [GameController::class, 'destroy'])->name('games.destroy');

        /*
         * The seed a game's randomness is drawn from. Assigned at creation and changeable only while
         * the game is in setup, which is why it is not a field on `games.update`: the metadata form
         * saves a name and a status long after that, and would carry a prohibited field with it.
         */
        Route::put('games/{game}/seed', [GameController::class, 'updateSeed'])->name('games.seed.update');

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

        /*
         * Agent accounts, and the tokens their seats authenticate with.
         *
         * Seating an agent is **not** here: an agent is added to a roster through the game's own seat
         * routes above, like any other account, because a roster is one game's business and a
         * gamemaster runs it. Only minting the credential is an administrator's, which is why these
         * two routes hang off the agent rather than off the game.
         *
         * `scopeBindings()` makes the seat resolve through the agent's own seats, so a seat id
         * belonging to somebody else 404s instead of being issued a token through the wrong agent's
         * URL — the same reason the game seat routes are scoped. `User $user` therefore has to stay
         * first in both controller signatures.
         *
         * The parameter is `{gameSeat}` rather than `{seat}` because scoping resolves the relation
         * from the **parameter name**, not from the path segment: `{seat}` would look for
         * `User::seats()`, and this side of the relation is deliberately called `gameSeats()` (see
         * `App\Models\User`).
         *
         * The path says `credentials` and not `seats/{seat}/credential`, which would otherwise be the
         * natural nesting. `GameSeatAdminTest` sweeps every route whose URI contains `seats` and
         * fails any that accepts `DELETE`, because seats are retired and never deleted — and a revoke
         * route nested under `seats` matches that sweep by wording alone. The invariant is worth more
         * than the prettier URL, so the resource being addressed is named for what is actually
         * deleted: the credential.
         *
         * The writes carry `throttle:6,1`, matching the invitation POST: both hand out a secret.
         */
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('agents/create', [AgentController::class, 'create'])->name('agents.create');
        Route::post('agents', [AgentController::class, 'store'])->middleware('throttle:6,1')->name('agents.store');
        Route::get('agents/{user}', [AgentController::class, 'show'])->name('agents.show');

        /*
         * Seating an agent from its own screen. The same act as `admin.games.seats.store` approached
         * from the other end, and it exists because a token belongs to a seat: without this, finishing
         * a newly created agent meant leaving for a game's roster and coming back.
         *
         * It is not a second roster. `AgentSeatController` refuses a non-agent account and seats as a
         * player only, so people are still added where the whole roster is visible and a gamemaster
         * can do it.
         */
        Route::post('agents/{user}/seats', [AgentSeatController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('agents.seats.store');

        Route::scopeBindings()->middleware('throttle:6,1')->group(function () {
            Route::post('agents/{user}/credentials/{gameSeat}', [AgentCredentialController::class, 'store'])
                ->name('agents.credential.store');
            Route::delete('agents/{user}/credentials/{gameSeat}', [AgentCredentialController::class, 'destroy'])
                ->name('agents.credential.destroy');
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

/*
 * Workstation conveniences, and the first of the two gates in front of them: outside `local` this
 * file is never read, so the routes in it do not exist and their URLs are ordinary 404s. The
 * controller behind each one checks the environment again for itself — see `routes/dev.php`.
 *
 * The check is written here, at the point of inclusion, rather than inside the file it guards, so
 * that reading `web.php` is enough to know these routes are not in production.
 */
if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}
