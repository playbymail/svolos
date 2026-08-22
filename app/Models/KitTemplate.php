<?php

namespace App\Models;

use App\Generation\Kit;
use Database\Factories\KitTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved opening kit in a gamemaster's private library.
 *
 * A kit is what every player in a game begins holding — a colony's worth of units and a ship's
 * worth. `App\Generation\Kit` is the thing itself; this is a row that remembers one, so a gamemaster
 * can draw one, edit it, download it and use it at as many games as they like.
 *
 * **The library is private, and `user_id` is the whole of that.** Every read is scoped to the
 * signed-in account and everything else 403s — see `Gamemaster\KitTemplateController` and
 * `.ai/rules/kit-templates.md`. There is no sharing, and no `game_id`: a kit outlives any one game,
 * which is the reason it is worth saving rather than just uploading.
 *
 * **Nothing here reaches a generated game.** Choosing a kit at the units stage copies `document`
 * onto `generation_runs.kit`; there is no foreign key in either direction. Deleting a row is
 * therefore always safe, which is why `destroy()` needs no guard about games in progress.
 *
 * `seed` and `file` together say how the kit arrived — drawn from a seed, read from a document, or
 * written by hand in the editor when both are null. `document` is `Kit::toArray()` verbatim, the
 * same shape the download emits and the upload accepts.
 *
 * `document` is out of `#[Fillable]`: it is composed by `Kit::toArray()` after parsing or drawing,
 * never assigned from request input, and mass-assigning it would let a posted array skip every
 * refusal `Kit` exists to make. `seed` and `file` are out for the same reason — they are facts about
 * where a document came from rather than fields anybody types.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $seed
 * @property string|null $file
 * @property array<string, mixed> $document
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
#[Fillable(['name'])]
class KitTemplate extends Model
{
    /** @use HasFactory<KitTemplateFactory> */
    use HasFactory;

    /**
     * The gamemaster this kit belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Read the stored document back as the kit it describes.
     *
     * `Kit::fromArray()` rather than `fromJson()`: what is in the column was written by `toArray()`
     * on a kit that had already been validated, so there is nothing left to refuse and a failure
     * here would be a bug rather than a message for anybody.
     */
    public function kit(): Kit
    {
        return Kit::fromArray($this->document);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'document' => 'array',
        ];
    }
}
