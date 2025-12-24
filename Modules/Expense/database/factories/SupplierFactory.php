<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Supplier;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        $faker = Factory::faker();
        
        return [
            'name' => $faker->company(),
            'phone' => $faker->phoneNumber(),
            'email' => $faker->unique()->companyEmail(),
            'tax_id' => $faker->numerify('###-###-####'),
            'address' => $faker->address(),
            'password' => bcrypt('password123'), // Default password for seeded suppliers
            'is_active' => $faker->boolean(90),
            'is_first_login' => true,
            'status' => $faker->randomElement(['online', 'offline', 'away']),
            'language' => $faker->randomElement(['ar', 'en']),
            'theme' => $faker->randomElement(['light', 'dark']),
            'total_orders' => $faker->numberBetween(0, 100),
            'completed_orders' => $faker->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
