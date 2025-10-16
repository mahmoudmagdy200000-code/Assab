<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ExpensesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = \Modules\Expense\Models\Expenses::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}

