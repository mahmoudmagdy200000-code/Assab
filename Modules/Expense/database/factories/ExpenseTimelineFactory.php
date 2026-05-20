<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseTimeline;

class ExpenseTimelineFactory extends Factory
{
    protected $model = ExpenseTimeline::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'action' => $this->faker->randomElement(['created', 'updated', 'submit', 'view', 'approve', 'reject']),
            'performed_by' => BranchManager::factory(),
            'performed_by_type' => 'branch_manager',
            'status' => 'draft',
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'created',
            'status' => 'draft',
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'submit',
            'status' => 'pending',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'approve',
            'performed_by_type' => 'brand_owner',
            'status' => 'approved',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'reject',
            'performed_by_type' => 'brand_owner',
            'status' => 'rejected',
            'notes' => $this->faker->sentence(),
        ]);
    }
}
