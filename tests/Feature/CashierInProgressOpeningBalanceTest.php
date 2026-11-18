<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Verifies `/api/v1/cashier/my-shifts/in-progress` preserves recorded `0.00`
 * opening balances numerically instead of masking them as "Not yet recorded".
 */
class CashierInProgressOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_shifts_returns_numeric_zero_for_recorded_zero_opening_balance(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع الاختبار']);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Evening',
            'start_time' => '15:00:00',
            'end_time' => '23:59:00',
            'is_active' => true,
        ]);

        CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now(),
            'opening_balance' => '0.00',
            'closing_balance' => '0.00',
        ]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/in-progress');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1);

        $opening = $response->json('data.shifts.0.opening_balance');
        $this->assertNotSame('Not yet recorded', $opening);
        $this->assertEquals(0, $opening);
    }

    public function test_in_progress_shifts_returns_nonzero_opening_balance_numerically(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع الاختبار']);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '14:00:00',
            'is_active' => true,
        ]);

        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now(),
        ]);
        $shift->forceFill(['opening_balance' => '10.00'])->save();

        $response = $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/in-progress');

        $response->assertOk();
        $this->assertEquals(10, $response->json('data.shifts.0.opening_balance'));
    }
}
