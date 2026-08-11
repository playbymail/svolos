<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SessionDestroyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The session to end arrives as a **digest** in the request body, never as a route parameter and
     * never as the session identifier itself: the identifier is the live session cookie value, so a
     * URL carrying it would leak an impersonation credential into history, logs and referrers. See
     * `App\Models\Session` and `.ai/rules/sessions.md`.
     *
     * The shape is pinned to 64 lowercase hex characters — the output of sha256 — so a value that
     * could not possibly be a digest is rejected by validation rather than by a table scan.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
