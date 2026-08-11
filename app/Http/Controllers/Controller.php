<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Get the authenticated user for the request.
     *
     * `Request::user()` is typed `?User` because it has no way to know a route is behind the
     * `auth` middleware, so every action that needs the model itself would otherwise read
     * through a nullable. Narrowing it here once turns the middleware's runtime guarantee into
     * a checked one: if the guard ever did resolve nothing, the request leaves through the
     * usual unauthenticated redirect rather than a type error mid-action.
     *
     * Where a null user propagates to a correct outcome on its own — a `403` from an ownership
     * comparison, a validation rule that simply stays stricter — prefer `$request->user()?->…`
     * over this.
     *
     * @throws AuthenticationException
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
