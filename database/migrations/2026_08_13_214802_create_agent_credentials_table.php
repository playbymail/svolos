<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The bearer token an agent authenticates to `api/*` with, stored the same way an invitation
     * token is: sha256 of the plain text, never the plain text itself.
     *
     * The credential hangs off a **seat**, not off an account, and that one decision carries most of
     * the design. A seat is one account's place at one game, so a token is scoped to a single game by
     * construction and needs no abilities or scope list to say so. It also means an agent driving a
     * human's seat is a later row rather than a later migration.
     *
     * `game_seat_id` is unique: a seat has at most one live credential, so minting a replacement
     * overwrites the row and the previous token stops working. Rotation is the only revocation
     * available once the plain text is unrecoverable, which is exactly the property
     * `.ai/rules/invitations.md` records for resending an invitation.
     */
    public function up(): void
    {
        Schema::create('agent_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_seat_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Exactly the width of a sha256 hex digest. A longer value in this column means somebody
             * stored the plain text, which is the one thing this table must never hold.
             */
            $table->string('token', 64)->unique();

            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_credentials');
    }
};
