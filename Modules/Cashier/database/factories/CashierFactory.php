<?php

namespace Modules\Cashier\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class CashierFactory extends Factory
{
    protected $model = Cashier::class;

    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'name' => 'Cashier ' . $counter,
            'email' => 'cashier' . $counter . time() . '@example.com',
            'password' => Hash::make('password123'),
            'phone' => '+9665' . str_pad((time() + $counter), 8, '0', STR_PAD_LEFT),
            'image' => null,
            'branch_id' => null, // Will be passed from seeder
            'status' => 'active',
            'created_by' => null, // Will be passed from seeder
            'activated_at' => now(),
            'deactivated_at' => null,
        ];
    }

    /**
     * Indicate that the cashier is pending activation
     */
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'pending',
            'activated_at' => null,
        ]);
    }

    /**
     * Indicate that the cashier is deactivated
     */
    public function deactivated(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Indicate that the cashier is active
     */
    public function active(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'active',
            'activated_at' => now(),
            'deactivated_at' => null,
        ]);
    }
}
