<?php

namespace App\Models;

use App\Enums\GameRole;
use Database\Factories\GameSeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account's place at one game, and the game role it holds there.
 *
 * ## Seats are retired, never deleted
 *
 * There is deliberately **no destroy endpoint** for a seat, and adding one would be a mistake even
 * though the screen looks incomplete without it. Engine history keeps referring to seats — a turn
 * report names the seat that submitted it — so a deleted row turns recorded history into a dangling
 * reference. Leaving a game sets `is_active = false` instead, and coming back sets it true again.
 *
 * Two things follow from that, and both are load-bearing:
 *
 * - the unique index on `(game_id, user_id)` **counts retired seats**, so an account that once had a
 *   seat can never get a second one. `App\Http\Requests\Admin\GameSeatStoreRequest` refuses the
 *   duplicate with a message that says so rather than letting the database throw;
 * - the "assignable accounts" list on the game screen excludes every account that already holds a
 *   seat, active *or* retired, because the only way back for a departed account is reactivation.
 *
 * `role` is a **game** role and carries no application permissions whatsoever — see
 * `App\Enums\GameRole`. `is_active` is kept out of `#[Fillable]` so it can only change through the
 * retire and reactivate endpoints, never as a side effect of a write that was about something else.
 *
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property GameRole $role
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read User $user
 */
#[Fillable(['user_id', 'role'])]
class GameSeat extends Model
{
    /** @use HasFactory<GameSeatFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * Both repeat column defaults from `..._create_game_seats_table.php` so an unsaved `new GameSeat`
     * reads back as an active player instead of hitting the enum cast with a null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => GameRole::Player->value,
        'is_active' => true,
    ];

    /**
     * Get the game this seat is at.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get the account sitting in this seat.
     *
     * Not nullable: `game_seats.user_id` cascades on delete, so deleting an account takes its seats
     * with it rather than leaving a seat nobody can be held to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => GameRole::class,
            'is_active' => 'boolean',
        ];
    }
}
