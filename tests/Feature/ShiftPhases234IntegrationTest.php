<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Liability\ShiftLiabilityService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftTransferReturn;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportReopenService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Modules\Shift\Services\TransferRequestLifecycleService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftPhases234IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $senderCashier;

    private Cashier $recipientCashier;

    private Shift $shiftTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'S1-11 Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand 1', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->senderCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->recipientCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    }

    /**
     * Scenario 1:
     * Rejected handover request -> same-recipient correction -> valid revision and count
     * -> presentation and confirmation once -> previous review, snapshots, and records preserved.
     */
    public function test_scenario_1_rejected_request_same_recipient_correction_presentation_confirmation_once(): void
    {
        $sourceShift = CashierShift::factory()->create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(2),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $this->recipientCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
        ]);

        $this->actingAs($this->senderCashier, 'sanctum')->postJson("/api/cashier/shifts/{$sourceShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipientCashier->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $sourceShift->id)->sole();

        // Physical presentation before rejection
        $attemptId = $this->actingAs($this->senderCashier, 'sanctum')->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => 50000,
            'idempotency_key' => 's1-present-1',
        ])->assertOk()->json('data.id');

        // Recipient rejects handover with physical details (0 cash confirmed / retained)
        $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/reject", [
                'rejection_reason' => 'Discrepancy in notes',
                'confirmed_amount' => '0.00',
                'correction_reason' => 'actual_shortage',
                'transfer_attempt_id' => $attemptId,
            ])->assertOk();

        $this->assertSame('rejected', $handover->fresh()->status);
        $originalHandoverId = $handover->id;
        $rejectionEvidenceCount = DB::table('shift_transfer_rejection_evidence')->count();

        // Sender performs same-recipient correction
        $this->actingAs($this->senderCashier);
        app(HandoverService::class)->recordHandoverEdit($sourceShift, [
            'handover_amount' => '480.00',
            'handover_notes' => 'Corrected notes after recount',
            'correction_reason' => 'input_error',
        ]);

        $correctedHandover = $handover->fresh();
        $this->assertSame($originalHandoverId, $correctedHandover->id, 'Request ID must be preserved for same recipient');
        $this->assertSame('480.00', (string) $correctedHandover->handover_amount);
        $this->assertSame('pending', $correctedHandover->status);

        // Previous rejection evidence preserved
        $this->assertSame($rejectionEvidenceCount, DB::table('shift_transfer_rejection_evidence')->count());

        // Presentation of corrected amount
        $attempt2Id = $this->actingAs($this->senderCashier, 'sanctum')->postJson("/api/shift-transfers/handover/{$correctedHandover->id}/present", [
            'presented_halalas' => 48000,
            'idempotency_key' => 's1-present-2',
        ])->assertOk()->json('data.id');

        // Confirmation once
        $receiptService = app(ShiftTransferReceiptService::class);
        $receipt = $receiptService->confirmHandover(
            $correctedHandover->id,
            $this->recipientCashier,
            '480.00',
            $destShift->id,
            null,
            null,
            $attempt2Id
        );

        $this->assertNotNull($receipt);
        $this->assertSame('approved', $correctedHandover->fresh()->status);
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $correctedHandover->id)->count());

        // Duplicate confirmation is refused
        try {
            $receiptService->confirmHandover(
                $correctedHandover->id,
                $this->recipientCashier,
                '480.00',
                $destShift->id,
                null,
                null,
                $attempt2Id
            );
            $this->fail('Duplicate confirmation must be refused');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_NOT_PENDING', $e->getMessage());
        }

        // Only one receipt remains
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $correctedHandover->id)->count());
    }

    /**
     * Scenario 2:
     * Rejected physical attempt -> unreturned retained cash blocks replacement -> confirmed return
     * -> reopen affected report requiring recount -> replacement linked to original -> named recipient confirms once.
     */
    public function test_scenario_2_rejected_physical_attempt_prevents_replacement_until_confirmed_return_and_reopen_recount(): void
    {
        $sourceShift = CashierShift::factory()->create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(2),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $this->recipientCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
        ]);

        // End shift with handover
        $this->actingAs($this->senderCashier, 'sanctum')->postJson("/api/cashier/shifts/{$sourceShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipientCashier->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $sourceShift->id)->sole();

        // 1. Present physical cash
        $attemptId = $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
                'presented_halalas' => 50000,
                'idempotency_key' => 'integration-present-1',
            ])->assertOk()->json('data.id');

        // 2. Recipient rejects with physical count of 480 SAR (retained cash = 48000 halalas)
        $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/reject", [
                'rejection_reason' => 'Only 480 physically counted',
                'confirmed_amount' => '480.00',
                'correction_reason' => 'actual_shortage',
                'transfer_attempt_id' => $attemptId,
            ])->assertOk();

        // 3. Attempting replacement to a new recipient manager is blocked while retained cash is unreturned
        $lifecycleService = app(TransferRequestLifecycleService::class);
        $revNumber = (int) DB::table('shift_report_aggregates')
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $sourceShift->id)
            ->value('current_revision_number');

        try {
            $lifecycleService->replaceRecipient(
                'handover',
                $handover->id,
                $this->senderCashier,
                ['recipient_type' => 'branch_manager', 'recipient_id' => $this->manager->id],
                '500.00',
                expectedRevision: $revNumber,
                reason: 'Replacing after rejection',
                operationId: (string) Str::uuid()
            );
            $this->fail('Replacement must fail while physical cash is retained');
        } catch (ConflictHttpException $e) {
            $this->assertSame('PHYSICAL_RETURN_REQUIRED', $e->getMessage());
        }

        // The receiving report counts its own 20 SAR plus the sender-owned 480 SAR.
        $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/cashier/shifts/{$destShift->id}/end-with-handover", [
                'total_sales' => '20.00',
                'cash_collected' => '20.00',
                'counted_cash' => '500.00',
                'handover_amount' => '20.00',
                'handover_to_type' => 'branch_manager',
            ])->assertOk();
        $receivingCount = app(ShiftCashCountService::class)->currentFor($destShift->id);
        $this->assertSame(48000, $receivingCount->pending_incoming_counted_halalas);

        // 4. Recipient initiates physical return
        $returnId = $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/shift-transfer-attempts/{$attemptId}/returns", [
                'returned_halalas' => 48000,
                'reason' => 'Returning physical cash',
                'idempotency_key' => 'integration-return-1',
            ])->assertOk()->json('data.id');

        // 5. Sender confirms physical return
        $this->actingAs($this->senderCashier, 'sanctum')
            ->postJson("/api/shift-transfer-returns/{$returnId}/confirm")
            ->assertOk();

        // Verify physical return confirmed
        $returnRow = ShiftTransferReturn::findOrFail($returnId);
        $this->assertNotNull($returnRow->sender_confirmed_at);

        // 6. Report affected by return requires recount
        $this->assertTrue((bool) DB::table('shift_report_aggregates')
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $destShift->id)
            ->value('fresh_count_required'));

        $currentRevNumber = (int) DB::table('shift_report_aggregates')
            ->where('source_type', 'cashier_shift')
            ->where('source_id', $destShift->id)
            ->value('current_revision_number');

        // 7. In-flight reopen of affected report
        $reopenService = app(ShiftReportReopenService::class);
        $revAfterReopen = $reopenService->reopenCashierReport(
            $destShift,
            $this->recipientCashier,
            expectedRevision: $currentRevNumber,
            reason: 'Reopening to recount returned cash',
            operationId: (string) Str::uuid()
        );

        $this->assertSame($currentRevNumber + 1, $revAfterReopen->revision_number);
        // Due to fresh_count_required, count is NOT carried forward automatically
        $countAfterReopen = ShiftReportCashCount::where('report_revision_id', $revAfterReopen->id)->first();
        $this->assertNull($countAfterReopen, 'Recount is required after physical cash return');

        // Cashier records new count
        $this->actingAs($this->recipientCashier, 'sanctum')
            ->postJson("/api/shift-reports/{$destShift->id}/recount", ['counted_halalas' => 2000])
            ->assertOk();
        $afterReturnCount = app(ShiftCashCountService::class)->currentFor($destShift->id);
        $this->assertSame(2000, $afterReturnCount->gross_halalas);
        $this->assertSame(2000, $afterReturnCount->counted_halalas);
        $this->assertSame(0, $afterReturnCount->pending_incoming_counted_halalas);
        $this->assertSame(0, $afterReturnCount->variance_halalas);
        $this->assertSame('500.00', (string) $sourceShift->fresh()->total_sales);
        $this->assertSame(50000, app(ShiftCashCountService::class)->currentFor($sourceShift->id)->gross_halalas);

        // 8. Now replacement succeeds
        $replacement = $lifecycleService->replaceRecipient(
            'handover',
            $handover->id,
            $this->senderCashier,
            ['recipient_type' => 'branch_manager', 'recipient_id' => $this->manager->id],
            '480.00',
            expectedRevision: $revNumber,
            reason: 'Handover redirected to branch manager after return',
            operationId: (string) Str::uuid()
        );

        $this->assertNotNull($replacement);
        $this->assertSame($handover->id, $replacement->supersedes_id);
        $this->assertSame($replacement->id, $handover->fresh()->replacement_request_id);
        $this->assertNotNull($handover->fresh()->cancelled_at);
        $this->assertNotNull($handover->fresh()->superseded_at);

        // 9. Named recipient (manager) confirms receipt
        $managerWorkday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);

        $receipt = app(ShiftTransferReceiptService::class)->confirmManagerHandover(
            $replacement->id,
            $this->manager,
            '480.00'
        );

        $this->assertNotNull($receipt);
        $this->assertSame('approved', $replacement->fresh()->status);
    }

    /**
     * Scenario 3:
     * Corrected report -> old allocation superseded -> attempt to reopen submitted day blocked by Gate D-PR
     * -> locks, receipts, ledger, and history remain immutable.
     */
    public function test_scenario_3_corrected_report_supersedes_allocation_and_blocks_reopen_of_submitted_workday(): void
    {
        $shift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'cash_collected' => '80.00',
            'card_payments' => '0.00',
        ]);

        DB::transaction(function () use ($shift) {
            $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->senderCashier->id, 0);
            app(ShiftCashCountService::class)->record($shift, $rev1, 10000, 0, 0, 8000);
        });

        // Setup liability allocation v1 (shortage 20 SAR = 2000 halalas)
        $liabilityService = app(ShiftLiabilityService::class);
        $shares = [
            ['type' => 'cashier', 'id' => $this->senderCashier->id, 'amount' => 2000],
        ];
        $alloc1 = $liabilityService->allocate($shift->id, $this->senderCashier, $shares, 0, false);
        $liabilityService->confirm($shift->id, $this->senderCashier, 1);
        $liabilityService->approve($shift->id, $this->manager, 1, false);

        $this->assertSame(1, $alloc1->version);
        $this->assertNull($alloc1->fresh()->superseded_at);

        // Correct shift report
        $correctionService = app(ShiftReportCorrectionService::class);
        $rev2 = $correctionService->correctCashierReport(
            $shift,
            $this->manager,
            [
                'total_sales' => '90.00',
                'cash_collected' => '80.00',
                'card_payments' => '0.00',
            ],
            expectedRevision: 1,
            reason: 'Correction of sales figure by manager audit',
            operationId: (string) Str::uuid()
        );

        $this->assertSame(2, $rev2->revision_number);
        // Verify allocation v1 is superseded
        $this->assertNotNull($alloc1->fresh()->superseded_at, 'Previous allocation must be superseded');

        // Audit correction recorded
        $this->assertDatabaseHas('shift_report_corrections', [
            'report_aggregate_id' => $rev2->report_aggregate_id,
            'field_name' => 'total_sales',
            'old_value' => '100.00',
            'new_value' => '90.00',
        ]);

        // Submit workday and lock liability
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);

        $workday->update([
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
            'can_reopen' => true,
        ]);

        $lock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $shift->id,
            'branch_manager_shift_id' => $workday->id,
            'report_revision' => '2',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        // Attempting to reopen submitted day via HTTP endpoint is blocked by Gate D-PR
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/branch-manager/workday/daily-close/reopen', [
                'reopen_reason' => 'Manager trying to reopen closed day',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        // Assert work remains submitted and lock remains active
        $this->assertTrue($workday->fresh()->daily_report_submitted);
        $this->assertNull($lock->fresh()->released_at);
        $this->assertNull($lock->fresh()->superseded_at);
    }

    /**
     * Scenario 4:
     * Predecessor shift report correction or pending transfer does NOT block incoming cashier start
     * -> cancelled requests remain visible in history without actionable confirmation.
     */
    public function test_scenario_4_outgoing_correction_or_pending_transfer_does_not_block_incoming_cashier_start(): void
    {
        $outgoingShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'total_sales' => '300.00',
            'cash_collected' => '300.00',
        ]);

        DB::transaction(function () use ($outgoingShift) {
            $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($outgoingShift, 'cashier', $this->senderCashier->id, 0);
            app(ShiftCashCountService::class)->record($outgoingShift, $rev1, 30000, 0, 0, 30000);
        });

        $currentRev = app(ShiftReportRevisionService::class)->currentCashierRevision($outgoingShift);

        // Outgoing has a pending handover request
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $outgoingShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->recipientCashier->id,
            'handover_amount' => '300.00',
            'status' => 'pending',
            'handover_date' => today(),
            'handover_time' => now(),
            'report_revision_id' => $currentRev->id,
        ]);

        // An incoming cashier is scheduled for the next shift on the same branch
        $incomingCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $incomingShift = CashierShift::factory()->create([
            'cashier_id' => $incomingCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        // Incoming cashier starts shift
        $startedShift = app(CashierShiftStartService::class)->startShift($incomingShift);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $startedShift->status);
        $this->assertNotNull($startedShift->actual_start_time);

        // Predecessor outgoing shift financial state is preserved and pending handover is not mutated
        $outgoingFresh = $outgoingShift->fresh();
        $this->assertSame('300.00', (string) $outgoingFresh->total_sales);
        $this->assertSame('pending', $handover->fresh()->status);

        // Cancelled requests remain in database with cancelled_at and cannot be confirmed
        $handover->update([
            'cancelled_at' => now(),
            'cancelled_by_type' => 'cashier',
            'cancelled_by_id' => $this->senderCashier->id,
            'cancellation_reason' => 'Superseded by replacement',
            'superseded_at' => now(),
        ]);

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover(
                $handover->id,
                $this->recipientCashier,
                '300.00',
                $incomingShift->id
            );
            $this->fail('Cancelled handover cannot be confirmed');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_CANCELLED', $e->getMessage());
        }

        $this->assertSame(0, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->count());
    }
}
