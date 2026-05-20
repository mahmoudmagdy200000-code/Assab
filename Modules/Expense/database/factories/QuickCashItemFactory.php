<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\QuickCashExpense;
use Modules\Expense\Models\QuickCashItem;

class QuickCashItemFactory extends Factory
{
    protected $model = QuickCashItem::class;

    public function definition(): array
    {
        return [
            'quick_cash_expense_id' => QuickCashExpense::factory(),
            'title' => $this->faker->words(3, true),
            'amount' => $this->faker->randomFloat(2, 50, 2000),
        ];
    }
}
