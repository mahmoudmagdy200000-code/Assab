<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\PreApprovalRequest;
use Modules\Expense\Models\Expense;

class PreApprovalRequestFactory extends Factory
{
    protected $model = PreApprovalRequest::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'purpose' => $this->faker->sentence(10),
            'estimated_amount' => $this->faker->randomFloat(2, 500, 50000),
            'priority' => $this->faker->randomElement(['high', 'medium', 'low']),
        ];
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }
}
