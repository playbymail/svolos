<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of the framework's `sessions` table, read as a model so an administrator can see and end
 * signed-in browsers.
 *
 * **`id` is the live session identifier, not a database key.** It is the value the browser holds in
 * its session cookie, so anything that learns it can impersonate that browser for as long as the
 * session lives. Two consequences govern everything in this class and its callers:
 *
 * - it must never reach the frontend and must never be a route parameter — `#[Hidden]` keeps it out
 *   of serialisation as a backstop, and every surface addresses a session by {@see digest()};
 * - a digest cannot be turned back into an id, so {@see findByDigest()} compares candidates in PHP
 *   with `hash_equals` rather than in SQL. SQLite has no `sha2()`, so there is no query to write.
 *
 * See `.ai/rules/sessions.md`.
 *
 * The table is created inside `database/migrations/0001_01_01_000000_create_users_table.php` and
 * `user_id` deliberately carries **no** foreign key, so deleting a user does not cascade to their
 * sessions — `App\Http\Controllers\Admin\UserController::destroy()` deletes them explicitly.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $payload
 * @property int $last_activity
 * @property-read User|null $user
 */
#[Hidden(['id', 'payload'])]
class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory;

    /**
     * The framework's session handler writes this table through the query builder and keeps no
     * `created_at`/`updated_at`; `last_activity` is the only clock it maintains.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The session identifier is a 40-character random string, not an auto-incrementing integer.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Browser tokens mapped to the name to show, in the order they must be tested.
     *
     * The order is the whole algorithm. Every Chromium browser also claims `Chrome`, and Chrome
     * itself also claims `Safari`, so the most specific token has to win: `Edg` before `Chrome`,
     * `Chrome` before `Safari`. The iOS variants come first because Apple requires every iOS
     * browser to use WebKit and advertise `Safari` too.
     *
     * @var array<string, string>
     */
    private const array BROWSER_TOKENS = [
        'EdgiOS' => 'Edge',
        'CriOS' => 'Chrome',
        'FxiOS' => 'Firefox',
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'Opera' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Vivaldi' => 'Vivaldi',
        'Firefox' => 'Firefox',
        'Chromium' => 'Chromium',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
        'Trident' => 'Internet Explorer',
        'MSIE' => 'Internet Explorer',
        'curl' => 'curl',
    ];

    /**
     * Platform tokens mapped to the name to show, in the order they must be tested.
     *
     * `Android` has to precede `Linux` because an Android user agent says `Linux; Android`, and the
     * iOS device tokens have to precede `Mac OS X` because iOS says `like Mac OS X`.
     *
     * @var array<string, string>
     */
    private const array PLATFORM_TOKENS = [
        'Windows' => 'Windows',
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'iPod' => 'iOS',
        'CrOS' => 'ChromeOS',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * The name shown when a user agent is missing or matches nothing known.
     */
    public const string UNKNOWN = 'Unknown';

    /**
     * Hash a session identifier into the value that is safe to hand to the frontend.
     *
     * A plain unsalted sha256, for the same reason `Invitation::hashToken()` is one: the digest has
     * to be reproducible so a value coming back from the browser can be matched against the rows we
     * hold, and the input is 40 random characters from the framework's own generator rather than a
     * human-chosen secret, so a password hash's slowness buys nothing.
     */
    public static function digestFor(string $id): string
    {
        return hash('sha256', $id);
    }

    /**
     * Get the identifier this session is addressed by outside the server.
     */
    public function digest(): string
    {
        return static::digestFor($this->id);
    }

    /**
     * Resolve the session a digest refers to, or null when no row matches.
     *
     * Every candidate is loaded and compared in PHP with `hash_equals`. That is not laziness about
     * writing a query: SQLite has no `sha2()`, so the digest cannot be computed in SQL, and the
     * digest by design carries nothing that could narrow the search. A malformed digest is rejected
     * up front so a stray value never costs a table scan.
     */
    public static function findByDigest(string $digest): ?self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            return null;
        }

        return static::query()
            ->get()
            ->first(fn (self $session): bool => hash_equals($session->digest(), $digest));
    }

    /**
     * Determine whether this row is the session making the current request.
     *
     * Compared with `hash_equals` because the argument is the live session identifier, and null
     * fails closed so a request with no session can never be told it is looking at itself.
     */
    public function isCurrent(?string $currentSessionId): bool
    {
        return $currentSessionId !== null
            && hash_equals($this->id, $currentSessionId);
    }

    /**
     * Get the moment this session was last seen.
     *
     * `last_activity` stays a plain integer — it is what the framework's session handler writes and
     * reads — and this helper is the only place that turns it into a date.
     */
    public function lastActiveAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->last_activity);
    }

    /**
     * Get the browser name parsed out of the stored user agent.
     */
    public function browser(): string
    {
        return $this->matchUserAgentToken(self::BROWSER_TOKENS);
    }

    /**
     * Get the operating system name parsed out of the stored user agent.
     */
    public function platform(): string
    {
        return $this->matchUserAgentToken(self::PLATFORM_TOKENS);
    }

    /**
     * Get the account this session is signed in as.
     *
     * Nullable because the column is: the framework writes a row for guests too, and `user_id` has
     * no foreign key so a row can also outlive the account it named.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to sessions belonging to an account.
     *
     * The administration screen lists signed-in browsers, and a guest's session row is not one.
     *
     * @param  Builder<Session>  $query
     */
    #[Scope]
    protected function authenticated(Builder $query): void
    {
        $query->whereNotNull('user_id');
    }

    /**
     * Return the first token from the given map that appears in the stored user agent.
     *
     * @param  array<string, string>  $tokens
     */
    private function matchUserAgentToken(array $tokens): string
    {
        $userAgent = $this->user_agent;

        if ($userAgent === null || $userAgent === '') {
            return self::UNKNOWN;
        }

        foreach ($tokens as $token => $name) {
            if (str_contains($userAgent, $token)) {
                return $name;
            }
        }

        return self::UNKNOWN;
    }
}
