<?php

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Controller::authenticatedUser()
|--------------------------------------------------------------------------
|
| Every caller is behind the `auth` middleware, so the null branch of this guard is unreachable
| over HTTP — which is precisely why it needs a test of its own. Nothing in the feature suite
| would notice if the guard stopped guarding and started handing back something else, and the
| whole point of it is that PHPStan level 8 is satisfied by real behaviour rather than by an
| assertion about behaviour.
|
*/

/**
 * Expose the protected guard so both of its branches can be driven directly.
 */
function guardedController(): object
{
    return new class extends Controller
    {
        public function resolve(Request $request): User
        {
            return $this->authenticatedUser($request);
        }
    };
}

test('the guard returns the user the request resolves', function () {
    $user = new User(['name' => 'Ada']);

    $request = Request::create('/settings/profile');
    $request->setUserResolver(fn (): User => $user);

    expect(guardedController()->resolve($request))->toBe($user);
});

test('the guard rejects a request that resolves no user', function () {
    $request = Request::create('/settings/profile');
    $request->setUserResolver(fn () => null);

    expect(fn () => guardedController()->resolve($request))
        ->toThrow(AuthenticationException::class);
});
