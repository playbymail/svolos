<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per invocation of a generator, **including the ones that were thrown away**. This table
     * is the answer to "which seed produced this game, and what was tried before it": a run keeps its
     * `seed` forever, so an accepted stage can be replayed exactly and a rejected one can still be
     * explained. Deleting rows for tidiness would delete the record the whole design exists to keep.
     *
     * There is no status column. `accepted_at` and `superseded_at` already say whether a run is
     * pending, accepted or replaced, and `App\Enums\GenerationRunStatus` derives it the way
     * `InvitationStatus` does — a stored copy is a second source of truth that goes stale.
     *
     * `attempt` counts from 1 within a game and stage, so the screen can say "attempt 3" without
     * counting rows, and the unique key on `(game_id, stage, attempt)` is what stops two runs
     * claiming the same number if two requests ever race.
     *
     * `summary` is the generator's own account of what it produced — the star mix, the realised
     * minimum separation — stored rather than recomputed because it describes *that* run, which for a
     * superseded one no longer exists anywhere else.
     */
    public function up(): void
    {
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->unsignedInteger('seed');
            $table->unsignedInteger('attempt');
            $table->json('summary')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'stage', 'attempt']);
            $table->index(['game_id', 'stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_runs');
    }
};
