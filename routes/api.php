<?php

use App\Http\Controllers\Api\AgentIdentityController;
use Illuminate\Support\Facades\Route;

/*
 * The agent surface.
 *
 * Everything here is authenticated by a bearer token and nothing else — see
 * `App\Http\Middleware\AuthenticateAgent`. The group is registered without `web`, so these routes
 * have no session, no cookies and no CSRF token: an agent runs in a sandbox somewhere else and holds
 * one secret, which is the whole point of the design.
 *
 * `throttle:agent` comes **before** `agent`, so a flood is turned away before it costs a database
 * lookup. It is written on this group rather than folded into the `api` middleware group so that it
 * shows up in `gatherMiddleware()`, which is what lets `AgentApiTest` assert it per route: a group's
 * contents are invisible there, and a limit nothing can see is a limit nothing will notice losing.
 * See `App\Providers\AppServiceProvider::configureRateLimiting()` for why there are two limits.
 *
 * `AgentApiTest` sweeps the route collection and fails if any route named `api.*` is missing either
 * middleware, the way `AdminAccessTest` does for `admin.*`. A route added here without them would be
 * an unauthenticated, unbounded window into a game.
 *
 * The `v1` prefix is not decoration. Agents are deployed somewhere this application cannot reach, so
 * a change to a payload cannot be rolled out to them at the same moment it ships here; a version in
 * the path is what lets the old shape keep answering until they catch up.
 */
Route::middleware(['throttle:agent', 'agent'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function () {
        Route::get('me', AgentIdentityController::class)->name('me');
    });
