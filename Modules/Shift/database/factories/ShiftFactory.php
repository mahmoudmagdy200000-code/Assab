<?php

namespace Modules\Shift\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\Shift;

class ShiftFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Shift::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        // Automatically create branch if not provided
        $branch = Branch::first() ?? Branch::factory()->create();

        $startHour = $this->faker->numberBetween(6, 10);
        $endHour = $startHour + 8;

        return [
            'name' => $this->faker->randomElement(['Morning', 'Afternoon', 'Evening', 'Night']).' Shift',
            'start_time' => sprintf('%02d:00:00', $startHour),
            'end_time' => sprintf('%02d:00:00', $endHour),
            'branch_id' => $branch->id,
            'is_active' => true,
        ];
    }
}
