<?php

namespace Modules\Shift\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;

class CashierShiftFactory extends Factory
{
    protected $model = CashierShift::class;

    public function definition(): array
    {
        return [
            'cashier_id' => Cashier::factory(),
            'shift_id' => Shift::factory(),
            'shift_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'status' => ShiftStatus::NOT_STARTED,
            'opening_balance' => 0,
            'closing_balance' => 0.00,
            'expected_balance' => 0.00,
            'total_sales' => 0.00,
            'net_sales' => 0.00,
            'vat_amount' => 0.00,
            'cash_collected' => 0.00,
            'card_payments' => 0.00,
            'variance' => 0,
            'pos_receipt' => null,
            'actual_start_time' => null,
            'actual_end_time' => null,
            'next_cashier_id' => null,
            'handed_over_at' => null,
            'handover_notes' => null,
            'original_cashier_id' => null,
            'reassigned_by' => null,
            'reassignment_reason' => null,
            'reassigned_at' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(2),
        ]);
    }

    public function completed(): static
    {
        $totalSales = $this->faker->randomFloat(2, 1000, 10000);
        $vatAmount = $totalSales * 0.15;
        $netSales = $totalSales - $vatAmount;
        $cashCollected = $this->faker->randomFloat(2, 500, 5000);
        $cardPayments = $totalSales - $cashCollected;

        return $this->state(fn (array $attributes) => [
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => now()->subHours(8),
            'actual_end_time' => now(),
            'total_sales' => $totalSales,
            'net_sales' => $netSales,
            'vat_amount' => $vatAmount,
            'cash_collected' => $cashCollected,
            'card_payments' => $cardPayments,
            'closing_balance' => $cashCollected,
            'variance' => 0,
        ]);
    }

    public function withVariance(): static
    {
        $totalSales = $this->faker->randomFloat(2, 1000, 10000);
        $vatAmount = $totalSales * 0.15;
        $netSales = $totalSales - $vatAmount;
        $cashCollected = $this->faker->randomFloat(2, 500, 5000);
        $cardPayments = $totalSales - $cashCollected - 100; // Create variance
        $variance = 100;

        return $this->state(fn (array $attributes) => [
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => now()->subHours(8),
            'actual_end_time' => now(),
            'total_sales' => $totalSales,
            'net_sales' => $netSales,
            'vat_amount' => $vatAmount,
            'cash_collected' => $cashCollected,
            'card_payments' => $cardPayments,
            'closing_balance' => $cashCollected,
            'variance' => $variance,
        ]);
    }
}
