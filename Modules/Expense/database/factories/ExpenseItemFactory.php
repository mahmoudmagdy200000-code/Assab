<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Category;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseItem;

class ExpenseItemFactory extends Factory
{
    protected $model = ExpenseItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(2, 1, 100);
        $unitPrice = $this->faker->randomFloat(2, 10, 500);
        $totalAmount = $quantity * $unitPrice;

        return [
            'expense_id' => Expense::factory(),
            'invoice_detail_id' => null,
            'category_id' => Category::factory()->purchase(),
            'name' => $this->faker->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
        ];
    }
}
