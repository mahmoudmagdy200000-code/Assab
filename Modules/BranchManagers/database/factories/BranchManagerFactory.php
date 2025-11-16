<?php

namespace Modules\BranchManagers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerFactory extends Factory
{
    protected $model = BranchManager::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '+9665' . $this->faker->numerify('########'),
            'password' => Hash::make('password123'),
            'branch_id' => null, // وستمرره دائمًا من Seeder

            'image' => null,
            'status' => 'active',
            'is_active' => true,
            'is_first_login' => false,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the manager is pending
     */
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the manager is suspended
     */
    public function suspended(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'suspended',
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the manager is on first login
     */
    public function firstLogin(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_first_login' => true,
        ]);
    }

    /**
     * Indicate that the manager is inactive
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }
}
