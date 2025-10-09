<?php

namespace Modules\Cashier\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = \Modules\Cashier\Models\Shift::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}

