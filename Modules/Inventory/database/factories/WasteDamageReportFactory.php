<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Enums\WasteDamageReportStatus;
use Modules\Inventory\Models\WasteDamageReport;

class WasteDamageReportFactory extends Factory
{
    protected $model = WasteDamageReport::class;

    public function definition(): array
    {
        $branch = Branch::first() ?? Branch::factory()->create();
        $manager = BranchManager::first() ?? BranchManager::factory()->create(['branch_id' => $branch->id]);

        return [
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => WasteDamageReportStatus::PENDING,
            'submitted_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WasteDamageReportStatus::PENDING,
            'submitted_at' => null,
        ]);
    }

    /**
     * Staff-submitted report awaiting Branch Manager confirmation
     * (the `isStaffInventored` UI flag is derived from staff assignment + submitted_at).
     */
    public function staffSubmitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WasteDamageReportStatus::PENDING,
            'assigned_to_type' => 'staff',
            'submitted_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WasteDamageReportStatus::COMPLETED,
            'submitted_at' => now(),
        ]);
    }
}
