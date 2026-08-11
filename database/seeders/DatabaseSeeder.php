<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The manifest `php artisan db:seed` runs, and the only seeder that may be run anywhere.
 *
 * It calls seeders and creates nothing itself. Each seeder it lists is responsible for deciding
 * whether it is allowed to run in the current environment, which keeps that decision next to the
 * data it protects instead of in a list that a later edit can silently drop an entry from.
 *
 * The starter kit's unguarded `test@example.com` account used to be created here. It is gone rather
 * than moved: `DevelopmentUserSeeder` supersedes it with six named accounts whose credentials come
 * from helpers, and leaving an account with a well-known address and the factory's shared
 * `password` behind would have meant a single `php artisan db:seed` on a deployed installation
 * created exactly the account the guard in `DevelopmentUserSeeder` exists to prevent.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DevelopmentUserSeeder::class);
    }
}
