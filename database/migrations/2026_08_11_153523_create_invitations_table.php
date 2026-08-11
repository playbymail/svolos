<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `token` holds a sha256 hash of the token, never the token itself — 64 characters is exactly
     * the length of a hex digest, so a value longer than that is a bug rather than a long token. It
     * is unique because the hash is how an acceptance request is looked up, and it is the only
     * column an unauthenticated visitor can address a row by.
     *
     * `email` is unique because an invitation is upserted on the address: re-inviting somebody
     * reissues the one row rather than accumulating rows that would each carry a live link.
     *
     * `expires_at` is indexed because the pending scope filters on it, and `invited_by_id` is
     * `nullOnDelete` so deleting the administrator who sent an invitation neither deletes the
     * invitation nor leaves it pointing at a missing account.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token', 64)->unique();
            $table->string('role')->default(UserRole::Member->value);
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
