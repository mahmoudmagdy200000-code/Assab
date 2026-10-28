<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Feature tests verifying shift-start authorization boundaries.
 */
class ReviewerProbeStartByManagerAsCashierTest extends TestCase
{
    use RefreshDatabase;

    private function pendingShift(Cashier $c, Shift $t, string $date): CashierShift
    {
        return CashierShift::create([
            'cashier_id' => $c->id,
            'shift_id' => $t->id,
            'shift_date' => $date,
            'status' => ShiftStatus::NOT_STARTED->value,
        ]);
    }

    public function test_cashier_cannot_start_another_cashiers_shift_via_manager_route(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $tpl = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $actorCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $victimCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);

        $victimShift = $this->pendingShift($victimCashier, $tpl, today()->toDateString());

        // Cashier attempts to start shift using branch-manager route
        $res = $this->actingAs($actorCashier, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$victimShift->id}");

        $res->assertStatus(403);
        $this->assertEquals(ShiftStatus::NOT_STARTED->value, $victimShift->fresh()->status->value ?? $victimShift->fresh()->status);
    }

    public function test_cashier_cannot_start_future_shift_via_manager_route(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $tpl = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $actorCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $futureShift = $this->pendingShift($actorCashier, $tpl, today()->addDays(2)->toDateString());

        $res = $this->actingAs($actorCashier, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$futureShift->id}");

        $res->assertStatus(403);
        $this->assertEquals(ShiftStatus::NOT_STARTED->value, $futureShift->fresh()->status->value ?? $futureShift->fresh()->status);
    }

    public function test_branch_manager_cannot_start_future_dated_shift(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $tpl = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $futureShift = $this->pendingShift($cashier, $tpl, today()->addDays(3)->toDateString());

        $res = $this->actingAs($mgr, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$futureShift->id}");

        $res->assertStatus(400);
        $this->assertEquals(ShiftStatus::NOT_STARTED->value, $futureShift->fresh()->status->value ?? $futureShift->fresh()->status);
    }

    public function test_branch_manager_can_start_today_shift_for_cashier(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $tpl = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $todayShift = $this->pendingShift($cashier, $tpl, today()->toDateString());

        $res = $this->actingAs($mgr, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$todayShift->id}");

        $res->assertStatus(200);
        $this->assertEquals(ShiftStatus::IN_PROGRESS->value, $todayShift->fresh()->status->value ?? $todayShift->fresh()->status);
    }

    public function test_unknown_shift_id_returns_404_not_500(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);

        $res = $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/99999999/start");

        $this->assertEquals(404, $res->status(), "Expected 404 for non-existent shift, got {$res->status()}");
    }
}
