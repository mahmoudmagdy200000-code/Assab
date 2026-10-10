<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Transformers\HandoverDetailResource;
use Tests\TestCase;

/**
 * S1-11 Phase 1 Verification:
 * - BR-17 Rejection Lockout Removal (multiple rejections >= 2 remain correctable and do not lock out)
 * - Legacy 'rejected_final' records remain editable and safely transition to 'pending'
 * - 'approve_rejection' command is retired and responds 409 INVALID_STATE
 * - Transfer request tables carry supersession and cancellation fields (R4b foundation)
 */
class ShiftRejectionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $recipient;

    private CashierShift $senderShift;

    private HandoverService $handoverService;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Compatibility Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'BrandCompat', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->sender = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->recipient = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $template = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->senderShift = CashierShift::create([
            'cashier_id' => $this->sender->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now(),
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
        ]);

        $this->handoverService = app(HandoverService::class);
    }

    /**
     * Test 1: Multiple rejections (1st, 2nd, 3rd) do NOT permanently lock out the cashier (BR-17).
     * Status remains 'rejected', is_final_rejection is false, and cashier can edit.
     */
    public function test_multiple_rejections_do_not_lock_out_cashier_under_br17(): void
    {
        // Initial submission
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipient->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        // 1st Rejection by recipient
        $res1 = $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => '1st rejection: count mismatch',
            'confirmed_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $this->assertFalse($res1->json('data.rejection_details.is_final_rejection'));
        $this->assertTrue($res1->json('data.rejection_details.cashier_can_edit'));
        $this->assertSame(1, $res1->json('data.rejection_details.rejection_count'));

        // Cashier edits and resubmits
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        // 2nd Rejection by recipient (Previously locked out under 2-reject rule, now allowed under BR-17)
        $res2 = $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => '2nd rejection: notes missing',
            'confirmed_amount' => '470.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $this->assertFalse($res2->json('data.rejection_details.is_final_rejection'));
        $this->assertTrue($res2->json('data.rejection_details.cashier_can_edit'));
        $this->assertSame(2, $res2->json('data.rejection_details.rejection_count'));

        $handoverStatus = ShiftHandoverStatus::where('cashier_shift_id', $this->senderShift->id)->firstOrFail();
        $this->assertSame('rejected', $handoverStatus->manager_approval_status);
        $this->assertFalse($handoverStatus->isPermanentlyRejected());
        $this->assertTrue($handoverStatus->canCashierEdit());

        // Cashier edits and resubmits again after 2nd rejection!
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '470.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        // 3rd Rejection by recipient
        $res3 = $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => '3rd rejection: recount',
            'confirmed_amount' => '460.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $this->assertSame(3, $res3->json('data.rejection_details.rejection_count'));
        $this->assertFalse($res3->json('data.rejection_details.is_final_rejection'));
        $this->assertTrue($res3->json('data.rejection_details.cashier_can_edit'));

        $handoverStatus->refresh();
        $this->assertSame('rejected', $handoverStatus->manager_approval_status);
        $this->assertSame(3, $handoverStatus->rejection_count);
        $this->assertFalse($handoverStatus->isPermanentlyRejected());
    }

    /**
     * Test 2: Historical 'rejected_final' record compatibility (T03).
     * A legacy record marked 'rejected_final' is treated as correctable, can be edited,
     * and transitions cleanly to 'pending' upon edit without data loss.
     */
    public function test_legacy_rejected_final_record_compatibility_and_transition(): void
    {
        // Create an existing handover record in legacy 'rejected_final' state
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id,
            'handover_to_id' => $this->recipient->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => '500.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'rejected_final',
            'rejection_count' => 2,
            'rejection_reason' => 'Legacy permanent rejection',
        ]);

        $status = ShiftHandoverStatus::create([
            'cashier_shift_id' => $this->senderShift->id,
            'status' => HandoverStatus::REJECTED,
            'manager_approval_status' => 'rejected_final',
            'rejection_count' => 2,
            'rejection_reason' => 'Legacy permanent rejection',
            'reviewed_by_id' => $this->manager->id,
            'reviewed_by_type' => get_class($this->manager),
            'reviewed_at' => now(),
        ]);

        // Model checks
        $this->assertFalse($status->isPermanentlyRejected());
        $this->assertTrue($status->canCashierEdit());
        $this->assertFalse($handover->isFinalRejection());
        $this->assertTrue($handover->canReject());

        // Cashier edits the legacy rejected_final record
        $response = $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '490.00',
            'correction_reason' => 'actual_shortage',
            'handover_notes' => 'Corrected after legacy rejection',
        ])->assertOk();

        $this->assertSame('pending', $response->json('data.edit_details.status'));

        // Verify database state transitioned cleanly
        $status->refresh();
        $this->assertSame(HandoverStatus::PENDING, $status->status);
        $this->assertSame('pending', $status->manager_approval_status);
        $this->assertTrue($status->was_edited_after_rejection);
        $this->assertTrue($status->canBeApproved());

        $handover->refresh();
        $this->assertSame('pending', $handover->status);
        $this->assertSame('490.00', (string) $handover->handover_amount);
    }

    /**
     * Test 3: The 'approve_rejection' action is retired under BR-17.
     * Sending 'approve_rejection' to the rejection decision endpoint responds HTTP 409 INVALID_STATE.
     */
    public function test_approve_rejection_is_retired_and_returns_409_invalid_state(): void
    {
        // Create a rejected status
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $this->senderShift->id,
            'status' => HandoverStatus::REJECTED,
            'manager_approval_status' => 'rejected',
            'rejection_count' => 1,
            'rejection_reason' => 'Needs review',
            'reviewed_by_id' => $this->manager->id,
            'reviewed_by_type' => get_class($this->manager),
            'reviewed_at' => now(),
        ]);

        CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'rejected',
            'rejection_count' => 1,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/rejection-decision",
            [
                'decision' => 'approve_rejection',
                'manager_comment' => 'Attempting to finalize rejection',
            ]
        );

        $response->assertStatus(409);
        $this->assertSame('INVALID_STATE', $response->json('code'));
    }

    /**
     * Test 4: Verify migration and model attributes for supersession & cancellation (R4b foundation).
     * Both cashier_shift_handovers and branch_manager_cash_transfers carry identical fields.
     */
    public function test_transfer_request_tables_carry_supersession_and_cancellation_fields(): void
    {
        $expectedColumns = [
            'superseded_at',
            'supersedes_id',
            'cancelled_at',
            'cancelled_by_type',
            'cancelled_by_id',
            'cancellation_reason',
            'replacement_request_id',
        ];

        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $table) {
            foreach ($expectedColumns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Table {$table} must have column {$column}"
                );
            }
        }

        // Test CashierShiftHandover helpers
        $handover = new CashierShiftHandover;
        $this->assertFalse($handover->isCancelled());
        $this->assertFalse($handover->isSuperseded());

        $handover->cancelled_at = now();
        $this->assertTrue($handover->isCancelled());
        $this->assertFalse($handover->canApprove());

        // Test BranchManagerCashTransfer helpers
        $transfer = new BranchManagerCashTransfer;
        $this->assertFalse($transfer->isCancelled());
        $this->assertFalse($transfer->isSuperseded());

        $transfer->superseded_at = now();
        $this->assertTrue($transfer->isSuperseded());
    }

    /**
     * Test 5: Manager plain rejection preserves handover, status, and financial rows without destruction (Codex Audit Item 1).
     */
    public function test_manager_rejection_preserves_handover_status_and_financial_rows(): void
    {
        // End shift with handover to branch manager
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
        ])->assertOk();

        // Branch manager rejects handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Discrepancy in notes',
        ])->assertOk();

        // 1. Shift handover row must survive with status 'rejected'
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->first();
        $this->assertNotNull($handover, 'Handover record must NOT be deleted upon manager rejection');
        $this->assertSame('rejected', $handover->status);
        $this->assertSame(1, $handover->rejection_count);

        // 2. Shift handover status row must survive with manager_approval_status 'rejected'
        $status = ShiftHandoverStatus::where('cashier_shift_id', $this->senderShift->id)->first();
        $this->assertNotNull($status, 'Handover status record must NOT be deleted upon manager rejection');
        $this->assertSame('rejected', $status->manager_approval_status);
        $this->assertSame(1, $status->rejection_count);
        $this->assertTrue($status->canCashierEdit());

        // 3. Shift financial amounts must NOT be zeroed
        $shiftFresh = $this->senderShift->fresh();
        $this->assertSame('500.00', (string) $shiftFresh->total_sales, 'total_sales must NOT be zeroed out');
        $this->assertSame('500.00', (string) $shiftFresh->cash_collected, 'cash_collected must NOT be zeroed out');
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shiftFresh->status);
    }

    /**
     * Test 6: Legacy rejected_final requests from prior days are included in manager workday list (Codex Audit Item 2).
     */
    public function test_prior_day_legacy_rejected_final_is_included_in_manager_workday_list(): void
    {
        $managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today()->toDateString(),
                'status' => 'open',
            ]);

        // Create a prior-day (2 days ago) cashier shift and handover with legacy 'rejected_final'
        $priorTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
        ]);
        $priorCashierShift = CashierShift::create([
            'cashier_id' => $this->sender->id,
            'shift_id' => $priorTemplate->id,
            'shift_date' => today()->subDays(2)->toDateString(),
            'status' => ShiftStatus::COMPLETED->value,
            'total_sales' => '300.00',
            'cash_collected' => '300.00',
        ]);

        $priorHandover = CashierShiftHandover::create([
            'cashier_shift_id' => $priorCashierShift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '300.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->subDays(2)->toDateString(),
            'handover_time' => now()->subDays(2),
            'status' => 'rejected_final',
            'rejection_count' => 2,
            'rejection_reason' => 'Legacy rejection from two days ago',
        ]);

        $service = app(\Modules\Shift\Services\BranchManagerShiftService::class);
        $handovers = $service->getShiftHandovers($managerShift, 'to_manager', true);

        $this->assertTrue(
            $handovers->contains('id', $priorHandover->id),
            'Prior-day legacy rejected_final handover must be included in manager workday handovers list'
        );
    }

    /**
     * Test 7: Rejection details endpoint advertises correct capabilities under BR-17 (Codex Audit Item 3).
     */
    public function test_rejection_details_advertises_correct_capabilities_under_br17(): void
    {
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $this->senderShift->id,
            'status' => HandoverStatus::REJECTED,
            'manager_approval_status' => 'rejected',
            'rejection_count' => 2,
            'rejection_reason' => 'Second rejection',
            'reviewed_by_id' => $this->manager->id,
            'reviewed_by_type' => get_class($this->manager),
            'reviewed_at' => now(),
        ]);

        CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'rejected',
            'rejection_count' => 2,
        ]);

        // Branch Manager endpoint
        $resBM = $this->actingAs($this->manager, 'sanctum')->getJson(
            "/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/rejection-details"
        )->assertOk();

        $this->assertFalse($resBM->json('data.can_approve_rejection'), 'can_approve_rejection must be false');
        $this->assertTrue($resBM->json('data.can_request_corrections'), 'can_request_corrections must be true');
        $this->assertFalse($resBM->json('data.rejection_details.is_final_rejection'), 'is_final_rejection must be false');

        // Section C workday endpoint (BranchManagerShiftController@getRejectionDetails)
        $resWorkday = $this->actingAs($this->manager, 'sanctum')->getJson(
            "/api/v1/branch-manager/workday/handoffs/rejection/{$this->senderShift->id}"
        )->assertOk();

        $this->assertFalse($resWorkday->json('data.can_approve_rejection'), 'can_approve_rejection must be false in workday route');
        $this->assertTrue($resWorkday->json('data.can_request_corrections'), 'can_request_corrections must be true in workday route');
        $this->assertFalse($resWorkday->json('data.rejection_details.is_final_rejection'), 'is_final_rejection must be false in workday route');
    }

    /**
     * Test 8: Re-ending shift after rejection posts delta-only in custody and preserves sales breakdown and variance.
     */
    public function test_re_ending_shift_after_rejection_records_delta_only_and_preserves_breakdown_and_variance(): void
    {
        $aggregator = Aggregator::create([
            'name' => 'جاهز', 'code' => 'JAHEZ', 'commission_rate' => 15,
            'payment_terms' => 'Monthly', 'integration_type' => 'manual', 'is_active' => true,
        ]);

        // Initial submission
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'aggregators' => [
                ['aggregator_id' => $aggregator->id, 'amount' => '100.00'],
            ],
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
        ])->assertOk();

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(400.0, $custodyService->getPersonalBalanceOnly($this->sender->id));
        $this->assertDatabaseHas('shift_sales_breakdown', [
            'cashier_shift_id' => $this->senderShift->id,
            'aggregator_id' => $aggregator->id,
            'amount' => '100.00',
        ]);

        // Branch manager rejects handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Discrepancy in counting',
        ])->assertOk();

        // Re-end shift with the same figures: custody must remain 400.0 (delta = 0, NOT doubled to 800.0)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'aggregators' => [
                ['aggregator_id' => $aggregator->id, 'amount' => '100.00'],
            ],
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
        ])->assertOk();

        $this->assertSame(400.0, $custodyService->getPersonalBalanceOnly($this->sender->id), 'Custody balance must NOT double after re-ending with same cash amount');
        $this->assertDatabaseHas('shift_sales_breakdown', [
            'cashier_shift_id' => $this->senderShift->id,
            'aggregator_id' => $aggregator->id,
            'amount' => '100.00',
        ]);
    }

    /**
     * Test 9: Confirming handover without valid count on current revision or while in progress is rejected (409).
     */
    public function test_handover_cannot_be_confirmed_while_shift_is_in_progress_or_missing_cash_count(): void
    {
        // Initial submission to branch manager
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
        ])->assertOk();

        // Branch manager rejects handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Count mismatch',
        ])->assertOk();

        // Cashier edits handover request to 480.00 (Shift remains in_progress, current revision has no fresh count yet)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        // Branch manager attempts to confirm while shift is in progress / missing current revision count
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/approve", [
            'confirmed_amount' => '480.00',
        ])->assertStatus(409);

        // Cashier properly ends shift with count for current revision
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end", [
            'total_sales' => '480.00',
            'cash_collected' => '480.00',
            'counted_cash' => '480.00',
        ])->assertOk();

        // Now branch manager confirms successfully
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/approve", [
            'confirmed_amount' => '480.00',
        ])->assertOk();
    }

    /**
     * Test 10: Physical transfer attempt lifecycle survives ordinary rejection, edit, and re-presentation.
     */
    public function test_physical_transfer_attempt_cycle_survives_ordinary_rejection_and_re_presentation(): void
    {
        // Initial submission
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->firstOrFail();

        // Present physical transfer attempt 1
        $attemptRes1 = $this->actingAs($this->sender, 'sanctum')->postJson(
            "/api/v1/shift-transfers/handover/{$handover->id}/present",
            ['presented_halalas' => 50000, 'idempotency_key' => 'attempt-seq-1']
        )->assertOk();

        $attempt1Id = $attemptRes1->json('data.id');
        $this->assertNotNull($attempt1Id);

        // Plain rejection of typed request requires physical details
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Discrepancy noted in physical bundle',
        ])->assertStatus(409);

        // Branch manager rejects attempt with physical count of 480.00
        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/shift-transfer-attempts/{$attempt1Id}/reject",
            [
                'physical_halalas' => 48000,
                'reason' => 'Only 480 physically counted in bundle',
                'correction_reason' => 'actual_shortage',
            ]
        )->assertOk();

        // Branch manager initiates return of physical cash
        $returnRes = $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/shift-transfer-attempts/{$attempt1Id}/returns",
            [
                'returned_halalas' => 48000,
                'reason' => 'Returning physical bundle',
                'idempotency_key' => 'return-attempt-1',
            ]
        )->assertOk();
        $returnId = $returnRes->json('data.id');

        // Original sender confirms receipt of returned cash
        $this->actingAs($this->sender, 'sanctum')->postJson(
            "/api/v1/shift-transfer-returns/{$returnId}/confirm"
        )->assertOk();

        // Cashier edits handover to 480.00
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        // Present physical transfer attempt 2 for the corrected amount -> Must NOT fail with PREVIOUS_ATTEMPT_NOT_RETURNED
        $attemptRes2 = $this->actingAs($this->sender, 'sanctum')->postJson(
            "/api/v1/shift-transfers/handover/{$handover->id}/present",
            ['presented_halalas' => 48000, 'idempotency_key' => 'attempt-seq-2']
        )->assertOk();

        $attempt2Id = $attemptRes2->json('data.id');
        $this->assertNotNull($attempt2Id);
        $this->assertNotSame($attempt1Id, $attempt2Id);

        // Branch manager confirms receipt of attempt 2 -> Must succeed
        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/shift-transfer-attempts/{$attempt2Id}/confirm-receipt",
            ['confirmed_halalas' => 48000]
        )->assertOk();
    }

    /**
     * Test 11: Legacy rejected_final status displays 'Rejected (Awaiting Edit)' label instead of permanently rejected.
     */
    public function test_legacy_rejected_final_label_displays_awaiting_edit_not_permanently_rejected(): void
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id,
            'handover_to_id' => $this->manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'rejected_final',
            'rejection_count' => 2,
        ]);

        $resource = (new HandoverDetailResource($handover))->toArray(request());
        $this->assertSame('Rejected (Awaiting Edit)', $resource['status_label']);
    }
}
