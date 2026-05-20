<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\GroupedInvoice;

class GroupedInvoiceFactory extends Factory
{
    protected $model = GroupedInvoice::class;

    public function definition(): array
    {
        $paymentType = $this->faker->randomElement(['full', 'partial', 'deferred']);
        $paidAmount = $paymentType === 'deferred' ? 0 : $this->faker->randomFloat(2, 1000, 10000);

        return [
            'expense_id' => Expense::factory(),
            'payment_type' => $paymentType,
            'paid_amount' => $paidAmount,
            'due_date' => $this->faker->dateTimeBetween('now', '+90 days'),
        ];
    }
}
