<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `is_agent` marks an account driven by software rather than by a person. It is a column of its
     * own rather than a third `UserRole` case because it answers a different question: `role` decides
     * what an account may reach in the administration area, and agent-ness decides whether a human
     * may sign in as it at all. Folding the two together would be the beginning of the general role
     * system `.ai/rules/roles.md` rules out.
     *
     * It is stored rather than derived from "holds a credential" because a credential belongs to a
     * *seat*: once an agent can be delegated a human's seat, that seat carries a credential too, and
     * a derived flag would start calling the human an agent.
     *
     * Indexed because both the agents screen and the accounts screen filter on it on every request.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_agent')->default(false)->after('role')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_agent');
        });
    }
};
