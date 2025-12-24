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
            'name' => $this->faker->company(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'tax_id' => $this->faker->numerify('###-###-####'),
            'address' => $this->faker->address(),
            'password' => bcrypt('password123'), // Default password for seeded suppliers
            'is_active' => $this->faker->boolean(90),
            'is_first_login' => true,
            'status' => 'offline',
            'language' => 'ar',
            'theme' => 'light',
            'total_orders' => 0,
            'completed_orders' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
