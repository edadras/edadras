<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            ExerciseSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // The demo club is heavy, so it stays opt in. Run it on its own with
        // `php artisan db:seed --class=DemoClubSeeder`.
        if (app()->environment('local') && env('SEED_DEMO_CLUB', false)) {
            $this->call(DemoClubSeeder::class);
        }
    }
}
