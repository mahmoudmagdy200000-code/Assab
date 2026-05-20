<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Category;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseLine;

class ExpenseLineFactory extends Factory
{
    protected $model = ExpenseLine::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'invoice_detail_id' => null,
            'category_id' => Category::factory()->expense(),
            'name' => $this->faker->words(3, true),
            'price' => $this->faker->randomFloat(2, 50, 5000),
        ];
    }
}
