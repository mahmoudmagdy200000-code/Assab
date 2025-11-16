<?php

namespace Modules\Cashier\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;

class BranchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Branch::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}

