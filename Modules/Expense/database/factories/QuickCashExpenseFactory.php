<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\QuickCashExpense;

class QuickCashExpenseFactory extends Factory
{
    protected $model = QuickCashExpense::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'expense_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'expense_name' => $this->faker->words(3, true),
            'has_vat' => $this->faker->boolean(80),
            'invoice_number' => $this->faker->numerify('INV-####-####'),
        ];
    }

    public function withoutVat(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_vat' => false,
        ]);
    }
}
