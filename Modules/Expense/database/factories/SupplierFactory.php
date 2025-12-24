<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Supplier;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'tax_id' => fake()->numerify('###-###-####'),
            'address' => fake()->address(),
            'password' => bcrypt('password123'), // Default password for seeded suppliers
            'is_active' => fake()->boolean(90),
            'is_first_login' => true,
            'status' => fake()->randomElement(['online', 'offline', 'away']),
            'language' => fake()->randomElement(['ar', 'en']),
            'theme' => fake()->randomElement(['light', 'dark']),
            'total_orders' => fake()->numberBetween(0, 100),
            'completed_orders' => fake()->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
