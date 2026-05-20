<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\InvoiceDetail;
use Modules\Expense\Models\Supplier;

class InvoiceDetailFactory extends Factory
{
    protected $model = InvoiceDetail::class;

    public function definition(): array
    {
        $issueDate = $this->faker->dateTimeBetween('-60 days', 'now');
        $dueDate = $this->faker->dateTimeBetween($issueDate, '+90 days');
        $isTaxInvoice = $this->faker->boolean(70);

        return [
            'expense_id' => Expense::factory(),
            'grouped_invoice_id' => null,
            'supplier_id' => Supplier::factory(),
            'invoice_number' => $this->faker->numerify('INV-####-####'),
            'issue_date' => $issueDate,
            'is_tax_invoice' => $isTaxInvoice,
            'tax_id' => $isTaxInvoice ? $this->faker->numerify('###-###-####') : null,
            'payment_type' => $this->faker->randomElement(['full', 'partial', 'deferred']),
            'paid_amount' => $this->faker->randomFloat(2, 0, 5000),
            'due_date' => $dueDate,
        ];
    }

    public function fullPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => 'full',
            'due_date' => null,
        ]);
    }

    public function partialPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => 'partial',
        ]);
    }

    public function deferredPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => 'deferred',
            'paid_amount' => 0,
        ]);
    }
}
