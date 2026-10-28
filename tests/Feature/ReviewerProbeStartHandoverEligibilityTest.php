<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Feature tests verifying handover eligibility, boundary checks, and custody integrity.
 */
class ReviewerProbeStartHandoverEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function completedShiftFor(Cashier $cashier, Shift $template): CashierShift
    {
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update([
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now(),
        ]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
            'total_sales' => '100.00',
            'cash_collected' => '100.00',
            'counted_cash' => '100.00',
        ]);

        return $shift->fresh();
    }

    public function test_self_handover_is_rejected_and_persists_no_records(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);

        $shift = $this->completedShiftFor($sender, $template);

        $res = $this->actingAs($sender, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $sender->id,
            'handover_amount' => 100,
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['error_code' => 'HANDOVER_RECIPIENT_NOT_ELIGIBLE']);

        $this->assertDatabaseMissing('cashier_shift_handovers', [
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $sender->id,
        ]);

        $this->assertDatabaseMissing('cashier_custody_transactions', [
            'related_shift_id' => $shift->id,
        ]);
    }

    public function test_cross_branch_handover_is_rejected_and_persists_no_records(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $mgrA = BranchManager::factory()->create(['branch_id' => $branchA->id, 'is_active' => true, 'status' => 'active']);
        $mgrB = BranchManager::factory()->create(['branch_id' => $branchB->id, 'is_active' => true, 'status' => 'active']);

        $templateA = Shift::factory()->create(['branch_id' => $branchA->id, 'is_active' => true]);
        $sender = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $mgrA->id, 'status' => 'active']);
        $otherBranchCashier = Cashier::factory()->create(['branch_id' => $branchB->id, 'created_by' => $mgrB->id, 'status' => 'active']);

        $shift = $this->completedShiftFor($sender, $templateA);

        $res = $this->actingAs($sender, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $otherBranchCashier->id,
            'handover_amount' => 100,
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['error_code' => 'HANDOVER_RECIPIENT_NOT_ELIGIBLE']);

        $this->assertDatabaseMissing('cashier_shift_handovers', [
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $otherBranchCashier->id,
        ]);

        $this->assertDatabaseMissing('cashier_custody_transactions', [
            'related_shift_id' => $shift->id,
        ]);
    }

    public function test_inactive_recipient_handover_is_rejected_and_persists_no_records(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $deactivatedCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'deactivated']);

        $shift = $this->completedShiftFor($sender, $template);

        $res = $this->actingAs($sender, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $deactivatedCashier->id,
            'handover_amount' => 100,
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['error_code' => 'HANDOVER_RECIPIENT_NOT_ELIGIBLE']);

        $this->assertDatabaseMissing('cashier_shift_handovers', [
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $deactivatedCashier->id,
        ]);

        $this->assertDatabaseMissing('cashier_custody_transactions', [
            'related_shift_id' => $shift->id,
        ]);
    }

    public function test_eligible_same_branch_active_cashier_handover_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);
        $eligibleRecipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id, 'status' => 'active']);

        $shift = $this->completedShiftFor($sender, $template);

        $res = $this->actingAs($sender, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $eligibleRecipient->id,
            'handover_amount' => 100,
        ]);

        $this->assertTrue(in_array($res->status(), [200, 201]), "Expected 200 or 201, got {$res->status()}");

        $this->assertDatabaseHas('cashier_shift_handovers', [
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $eligibleRecipient->id,
        ]);
    }

    public function test_available_recipients_excludes_ineligible_users(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $mgrA = BranchManager::factory()->create(['branch_id' => $branchA->id, 'is_active' => true, 'status' => 'active']);
        $mgrB = BranchManager::factory()->create(['branch_id' => $branchB->id, 'is_active' => true, 'status' => 'active']);

        $templateA = Shift::factory()->create(['branch_id' => $branchA->id, 'is_active' => true]);
        $sender = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $mgrA->id, 'status' => 'active']);
        $otherBranchCashier = Cashier::factory()->create(['branch_id' => $branchB->id, 'created_by' => $mgrB->id, 'status' => 'active']);
        $deactivatedCashier = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $mgrA->id, 'status' => 'deactivated']);
        $eligibleCashier = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $mgrA->id, 'status' => 'active']);

        $shift = $this->completedShiftFor($sender, $templateA);

        $res = $this->actingAs($sender, 'sanctum')->getJson("/api/v1/branch-manager/shifts/{$shift->id}/available-recipients");
        $res->assertStatus(200);

        $recipients = collect($res->json('data.recipients') ?? $res->json('data') ?? []);
        $recipientIds = $recipients->pluck('id')->map(fn ($id) => (string) $id)->all();

        // Must NOT include self
        $this->assertNotContains((string) $sender->id, $recipientIds, 'Sender must not be present in eligible recipients');

        // Must NOT include other branch cashier
        $this->assertNotContains((string) $otherBranchCashier->id, $recipientIds, 'Other branch cashier must not be present');

        // Eligible cashier must be present
        $this->assertContains((string) $eligibleCashier->id, $recipientIds, 'Eligible same-branch cashier must be present');
    }
}
