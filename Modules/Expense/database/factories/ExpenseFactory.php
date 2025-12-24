<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Expense;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Supplier;
use App\Models\User;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $netAmount = $this->faker->randomFloat(2, 100, 10000);
        $vatRate = 0.15; // 15% VAT
        $vatAmount = $netAmount * $vatRate;
        $totalAmount = $netAmount + $vatAmount;

        return [
            'branch_manager_id' => BranchManager::factory(),
            'expense_type' => $this->faker->randomElement(['quick_cash', 'single_invoice', 'grouped_invoice', 'pre_approval']),
            'status' => 'draft',
            'total_amount' => $totalAmount,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'payment_method' => $this->faker->randomElement(['cash', 'supplier', 'custody']),
            'supplier_id' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];
    }

    public function quickCash(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => 'quick_cash',
        ]);
    }

    public function singleInvoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => 'single_invoice',
            'supplier_id' => Supplier::factory(),
        ]);
    }

    public function groupedInvoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => 'grouped_invoice',
        ]);
    }

    public function preApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => 'pre_approval',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'submitted_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function approved(): static
    {
        $submittedAt = $this->faker->dateTimeBetween('-60 days', '-30 days');
        $approvedAt = $this->faker->dateTimeBetween($submittedAt, 'now');

        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'submitted_at' => $submittedAt,
            'approved_by' => User::factory(),
            'approved_at' => $approvedAt,
        ]);
    }

    public function rejected(): static
    {
        $submittedAt = $this->faker->dateTimeBetween('-60 days', '-30 days');
        $rejectedAt = $this->faker->dateTimeBetween($submittedAt, 'now');

        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'submitted_at' => $submittedAt,
            'rejected_by' => User::factory(),
            'rejected_at' => $rejectedAt,
            'rejection_reason' => $this->faker->sentence(),
        ]);
    }
}
