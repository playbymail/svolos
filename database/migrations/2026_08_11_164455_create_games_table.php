<?php

use App\Enums\GameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `name` is unique because two games with the same name are indistinguishable to the people
     * playing them, and `short_name` is unique for a stronger reason: it is the identifier that goes
     * into turn reports and generated file names, so a collision means two games writing over each
     * other's output. It is capped at 16 characters for the same reason — it has to survive being
     * embedded in a filename — and validation uppercases it and restricts it to `[A-Z0-9-]` before it
     * reaches this column (see `App\Concerns\GameValidationRules`). The database stores whatever it is
     * given, so the case-folding is a validation rule, not a column property.
     *
     * `status` is indexed because `Game::unarchived()` filters on it, and it defaults to `setup`
     * because a game with no seats is exactly what a game in setup is. The default is repeated in
     * `Game::$attributes` so an unsaved `new Game` reads back as `setup` instead of hitting the enum
     * cast with a null; change one and you must change the other.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('short_name', 16)->unique();
            $table->string('status')->default(GameStatus::Setup->value)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
