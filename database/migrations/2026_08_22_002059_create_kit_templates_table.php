<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A gamemaster's saved opening kits.
 *
 * **A table rather than a column, and it is not the "inputs are columns, artefacts get tables" rule
 * being applied either way round.** That rule is about a `generation_runs` row: what a stage was
 * asked for lives on the run, what it produced gets a table of its own. A kit template is neither —
 * it belongs to a *person* rather than to a run, it outlives every game it is used in, and nothing
 * about a run's lifecycle should reach it. `generation_runs.kit` is the column that rule governs,
 * and it holds a **copy** of whatever document was used, so deleting a row here can never disturb a
 * game that has already been generated.
 *
 * `user_id` is the whole of ownership and the library is private: `KitTemplateController` scopes
 * every read to the signed-in account and 403s on anything else. It cascades, because a kit is
 * somebody's working document and means nothing without them. There is deliberately **no `game_id`**
 * — a kit is written once and used at as many games as its author likes, which is the reason it is
 * worth saving at all.
 *
 * `seed` is nullable and `file` is nullable, and between them they say how a kit arrived: drawn from
 * a seed, read from a document, or written by hand in the editor. Both null is the third case.
 *
 * `document` is `App\Generation\Kit::toArray()` verbatim — the same shape the download emits and the
 * upload accepts, so a kit round-trips through a file without losing anything.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kit_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('seed')->nullable();
            $table->string('file')->nullable();
            $table->json('document');
            $table->timestamps();

            /*
             * Scoped to the owner rather than global: the library is private, so two gamemasters may
             * each keep a kit called "Lean start" without either of them knowing the other exists.
             */
            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kit_templates');
    }
};
