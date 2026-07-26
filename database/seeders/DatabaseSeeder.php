<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * One realistic, fully-linked two-worlds demo (dashboard + mobile). See
     * FullDemoSeeder — it drives the real bridge/provisioner services so the
     * seeded data behaves exactly like runtime (mobile submissions reach the
     * responsible scoped accountant; one password logs into both worlds).
     */
    public function run(): void
    {
        $this->call([
            FullDemoSeeder::class,
        ]);
    }
}
