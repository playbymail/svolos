<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Fix the session identifier that every subsequent request in this test will use, and return it.
     *
     * `phpunit.xml` sets `SESSION_DRIVER=array`, and the harness sends no session cookie back
     * between requests, so `StartSession` mints a fresh identifier on every single request and there
     * is never a `sessions` row corresponding to the request being made. That would make the
     * "is this the current session?" behaviour — the `is_current` flag and the 403 on signing
     * yourself out — untestable, and a guard nothing exercises is a guard that has already stopped
     * working.
     *
     * Seeding the session cookie makes `StartSession` adopt this identifier instead of generating
     * one, which lets a test create a real row for the current session. `withCookie()` encrypts the
     * value the way `EncryptCookies` expects, and 40 random alphanumeric characters is what
     * `Session::isValidId()` requires — a value it rejects would be silently replaced.
     */
    protected function pinSessionId(?string $id = null): string
    {
        $id ??= Str::random(40);

        $this->withCookie((string) config('session.cookie'), $id);

        return $id;
    }
}
