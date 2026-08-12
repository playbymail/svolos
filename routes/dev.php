<?php

use App\Http\Controllers\Dev\AgentLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Development-only routes
|--------------------------------------------------------------------------
|
| **This file is only required when the application is in the `local` environment**, from the bottom
| of `routes/web.php`. Nothing in it may be added to any other routes file, and nothing that is not a
| workstation convenience may be added to it.
|
| The one route here signs a browser in as an existing account with no password, so that the
| application can be driven by hand or by an agent that is not permitted to type credentials into a
| form. `App\Http\Controllers\Dev\AgentLoginController` checks the environment again for itself, which
| is what protects a deploy that shipped a route cache built on somebody's laptop.
|
| The address goes in the path rather than the query string so the whole thing can be typed or pasted
| as one URL: `/__dev/log-me-in/user1@example.com?returnTo=/admin/games`. `[^/]+` is what lets an
| `@` and a `.` through the parameter.
|
*/

Route::get('__dev/log-me-in/{email}', AgentLoginController::class)
    ->where('email', '[^/]+')
    ->name('dev.login');
