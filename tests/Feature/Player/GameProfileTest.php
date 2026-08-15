<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| A player configuring their empire
|--------------------------------------------------------------------------
|
| `PUT /games/{game}/profile` is the only thing a player writes, and the seat it writes is resolved
| from the session rather than from the URL — there is no seat id to mistype, which is why this is the
| one write in the application with no scoped binding.
|
| Two rules are pinned here that no other test would catch. **A player cannot renumber themselves**:
| `number` is absent from `GameProfileUpdateRequest::rules()` *and* out of `GameSeat`'s `#[Fillable]`,
| and the test posts one to prove both together. And **unticking the notification box has to work**:
| the field is `required` rather than `sometimes` precisely because an absent field cannot turn
| anything off, so the test that earns its place posts `0` and asserts the column moved.
|
*/

test('a player names their empire and opts in', function () {
    $game = Game::factory()->create(['name' => 'The Long Dark']);
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->create();

    $response = $this->actingAs($member)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'The Analytical Reach',
        'email_notifications' => '1',
    ]);

    $response->assertRedirect(route('games.show', ['game' => $game]));

    /*
     * On the redirect, not on the page: `Inertia::flash()` rides the response that redirects, so
     * `$page->has('toast')` on the followed request will never find it.
     */
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'The Analytical Reach is ready.',
    ]);

    expect($seat->fresh())
        ->empire_name->toBe('The Analytical Reach')
        ->email_notifications->toBeTrue();
});

test('unticking the box turns the notification off again', function () {
    /*
     * The reason `email_notifications` is `required` rather than `sometimes`. A checkbox posts nothing
     * when it is unticked, so the screen sends a hidden `0` beside it — and a rule that let the field
     * be absent would read "turn it off" as "leave it alone", making the box impossible to untick.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    $this->actingAs($member)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Quiet Reach',
        'email_notifications' => '0',
    ])->assertRedirect();

    expect($seat->fresh())->email_notifications->toBeFalse();
});

test('the notification field cannot simply be left out', function () {
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    $this->actingAs($member)
        ->put(route('games.profile.update', ['game' => $game]), ['empire_name' => 'Quiet Reach'])
        ->assertSessionHasErrors('email_notifications');
});

test('an empire name is required and bounded', function () {
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)
        ->put(route('games.profile.update', ['game' => $game]), ['empire_name' => '', 'email_notifications' => '0'])
        ->assertSessionHasErrors('empire_name');

    $this->actingAs($member)
        ->put(route('games.profile.update', ['game' => $game]), [
            'empire_name' => str_repeat('a', GameSeat::EMPIRE_NAME_MAX_LENGTH + 1),
            'email_notifications' => '0',
        ])
        ->assertSessionHasErrors('empire_name');
});

test('a player cannot renumber their empire', function () {
    /*
     * Posted alongside a perfectly valid change, so the request succeeds and only the number is
     * refused. Two independent things have to hold for this to pass — `number` is not in
     * `GameProfileUpdateRequest::rules()`, so `validated()` never carries it, and it is out of
     * `GameSeat`'s `#[Fillable]`, so a future `fill($request->all())` could not write it either.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Renumbered',
        'email_notifications' => '0',
        'number' => 99,
    ])->assertRedirect();

    expect($seat->fresh())
        ->number->toBe($seat->number)
        ->empire_name->toBe('Renumbered');
});

test('a player cannot change their role or reactivate themselves through the profile', function () {
    $game = Game::factory()->create();
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Ambitious',
        'email_notifications' => '0',
        'role' => 'gamemaster',
        'is_active' => false,
    ])->assertRedirect();

    expect($seat->fresh())
        ->role->toBe(GameRole::Player)
        ->is_active->toBeTrue();
});

test('one player writing their profile leaves everybody else alone', function () {
    /*
     * The seat is resolved from the session, so there is no id anywhere for a request to point at
     * somebody else's row. This is what a scoped binding would be protecting if the seat were in the
     * URL — it is not, and this is the assertion that says so.
     */
    $game = Game::factory()->create();
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $mySeat = GameSeat::factory()->for($game)->for($mine)->create();
    $theirSeat = GameSeat::factory()->for($game)->for($theirs)->create();

    $this->actingAs($mine)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Only Mine',
        'email_notifications' => '1',
    ])->assertRedirect();

    expect($mySeat->fresh())->empire_name->toBe('Only Mine');
    expect($theirSeat->fresh())
        ->empire_name->toBeNull()
        ->email_notifications->toBeFalse();
});

test('a gamemaster cannot write a profile at the game they run', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Not Mine To Have',
        'email_notifications' => '1',
    ])->assertForbidden();
});

test('a retired player cannot write a profile', function () {
    $game = Game::factory()->create();
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->retired()->create();

    $this->actingAs($member)->put(route('games.profile.update', ['game' => $game]), [
        'empire_name' => 'Back From The Dead',
        'email_notifications' => '1',
    ])->assertForbidden();

    expect($seat->fresh())->empire_name->toBeNull();
});
