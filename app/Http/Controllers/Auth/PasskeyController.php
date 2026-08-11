<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasskeyUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Passkeys\Passkey;

/**
 * Renames a passkey.
 *
 * Fortify ships registration, listing and deletion for passkeys but no rename endpoint,
 * so this fills that single gap using the same ownership check and password-confirmation
 * middleware as Fortify's own passkey management routes.
 */
class PasskeyController extends Controller
{
    /**
     * Rename one of the authenticated user's passkeys.
     */
    public function update(PasskeyUpdateRequest $request, Passkey $passkey): RedirectResponse
    {
        abort_unless($passkey->user_id === $request->user()?->getKey(), 403);

        $passkey->update([
            'name' => $request->string('name')->toString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Passkey renamed.')]);

        return back();
    }
}
