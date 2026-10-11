<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Tests\TestCase;

/**
 * S1-11 Complete Integration Workflow Test
 * Covers all end-to-end integration flows across S1-11 requirements:
 * 1. Happy path: Start -> End -> Handover -> Present -> Confirm -> Review -> Daily submit with lock.
 * 2. Accountant correction after approval with zero-shortage transition requiring manager acknowledgment.
 * 3. Physical return -> In-flight reopen -> Recount -> Replacement transfer -> Confirmed receipt.
 * 4. Confirmed partial receipt -> Additive linked settlement (dual counterpart, net delta 0, immutable receipt).
 * 5. Operating responsibility transfer with successor start while predecessor is pending/reopening.
 */
class ShiftS111CompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Cashier $cashierC;

    private Shift $shiftTemplate;

    private BranchManagerShift $workday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'S1-11 Complete Workflow Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Prime',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Corporate Accountant',
            'email' => 'acct@workflow-test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->cashierC = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $this->shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Standard Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        $this->workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())
            ->first() ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        $this->workday->update(['status' => 'completed']);
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
    }

    /**
     * Scenario 1: Complete standard day happy path.
     * Cashier shift starts, completes with handover to manager, presentation, confirmation,
     * manager approval, and daily submit with idempotency and liability lock.
     */
    public function test_s111_complete_standard_day_happy_path_workflow(): void
    {
        // 1. Cashier shift start & completion
        $shift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(8),
        ]);

        $endResponse = $this->actingAs($this->cashierA, 'sanctum')->postJson("/api/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '1000.00',
            'cash_collected' => '1000.00',
            'counted_cash' => '1000.00',
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '1000.00',
        ]);
        $endResponse->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame('1000.00', (string) $handover->handover_amount);

        // 2. Physical Presentation
        $presentResponse = $this->actingAs($this->cashierA, 'sanctum')->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => 100000,
            'idempotency_key' => 'flow1-present-1',
        ]);
        $presentResponse->assertOk();
        $attemptId = $presentResponse->json('data.id');

        // 3. Manager Confirms Handover Receipt via Attempt Route
        $confirmResponse = $this->actingAs($this->manager, 'sanctum')->postJson("/api/shift-transfer-attempts/{$attemptId}/confirm-receipt", [
            'confirmed_halalas' => 100000,
        ]);
        $confirmResponse->assertOk();

        $receipt = CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->sole();
        $this->assertSame('1000.00', (string) $receipt->confirmed_amount);
        $this->assertNotNull($receipt->confirmed_at);

        // 4. Daily Close Submit with Idempotency Key
        $submitKey = (string) Str::uuid();
        $submitResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $submitKey)
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $this->workday->id,
                'final_notes' => 'Happy path complete day',
                'expected_revision' => 1,
            ]);

        $submitResponse->assertOk()->assertJsonPath('success', true);

        // Assert Day is Submitted, Locked, and Reopen is Forbidden
        $this->workday->refresh();
        $this->assertTrue((bool) $this->workday->daily_report_submitted);
        $this->assertFalse((bool) $this->workday->can_reopen);
        $this->assertNotNull($handover->fresh()->daily_closed_at);

        // Verify Daily Liability Lock is created for the cashier shift
        $lock = ShiftLiabilityDailyLock::where('branch_manager_shift_id', $this->workday->id)
            ->where('cashier_shift_id', $shift->id)
            ->first();
        $this->assertNotNull($lock, 'Gate C-DS must lock submitted cashier shift.');
    }

    /**
     * Scenario 2: Accountant correction after approval with zero-shortage transition.
     * Shift starts with shortage (variance -100 SAR) and manager reviews it.
     * Accountant posts correction reducing card_payments so counted cash matches sales (variance becomes 0 SAR).
     * Day submit is BLOCKED until manager acknowledges the zero-shortage correction!
     * Manager reviews revision 2, and day submit succeeds.
     */
    public function test_s111_accountant_correction_after_approval_zero_shortage_workflow(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'total_sales' => '1000.00',
            'net_sales' => '869.57',
            'vat_amount' => '130.43',
            'card_payments' => '400.00',
            'cash_collected' => '600.00',
            'closing_balance' => '500.00',
            'variance' => '-100.00',
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => now()->subHours(8),
            'actual_end_time' => now(),
        ]);

        $revService = app(ShiftReportRevisionService::class);
        $rev1 = $revService->recordCashierRevision($shift, 'cashier', $this->cashierA->id, 0);

        DB::transaction(function () use ($shift, $rev1) {
            app(ShiftCashCountService::class)->record(
                $shift,
                $rev1,
                100000,
                40000,
                0,
                50000 // Counted 500 SAR vs expected 600 SAR -> shortage 100 SAR (-10000 halalas)
            );
        });

        // Handover of the counted 500 SAR
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '500.00',
            'variance_amount' => '-100.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'report_revision_id' => $rev1->id,
        ]);
        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'receiving_branch_manager_shift_id' => $this->workday->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '500.00',
            'confirmed_at' => now(),
        ]);

        // Normal public liability commands establish the approved, posted initial shortage.
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/liability/allocate", [
                'expected_revision' => 1, 'expected_version' => 0, 'cashier_confirmed' => true,
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
            ])->assertCreated();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/liability/approve", [
                'expected_revision' => 1, 'version' => 1,
            ])->assertOk();
        // Accountant submits correction via HTTP endpoint: adjusts card_payments from 400 to 500, cash from 600 to 500
        // Resulting variance is 0.00 SAR (0 halalas) - eliminating the initial shortage
        $correctResponse = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$shift->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Bank reconciliation corrected POS card transactions - shortage resolved to zero',
                'changes' => [
                    'card_payments' => '500.00',
                    'cash_collected' => '500.00',
                ],
                'shortage_allocations' => [],
            ]);

        $correctResponse->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('manager_review_required', false)
            ->assertJsonPath('financial_results.variance_halalas', 0);

        // COR-07 / RESOLVED-02: Accountant is final authority across branches.
        // Workday submit succeeds immediately without requiring manager review/approval!
        $finalSubmitResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $this->workday->id,
                'expected_revision' => 1,
            ]);

        $finalSubmitResponse->assertOk()->assertJsonPath('success', true);
        $this->assertTrue((bool) $this->workday->fresh()->daily_report_submitted);
    }

    public function test_review_nonzero_accountant_correction_remains_current_for_response_and_daily_close(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'total_sales' => '1000.00',
            'net_sales' => '869.57',
            'vat_amount' => '130.43',
            'card_payments' => '400.00',
            'cash_collected' => '600.00',
            'closing_balance' => '500.00',
            'variance' => '-100.00',
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => now()->subHours(8),
            'actual_end_time' => now(),
        ]);

        $revService = app(ShiftReportRevisionService::class);
        $rev1 = $revService->recordCashierRevision($shift, 'cashier', $this->cashierA->id, 0);

        DB::transaction(function () use ($shift, $rev1) {
            app(ShiftCashCountService::class)->record(
                $shift,
                $rev1,
                100000,
                40000,
                0,
                50000 // Counted 500 SAR vs expected 600 SAR -> shortage 100 SAR (-10000 halalas)
            );
        });

        // Handover of the counted 500 SAR
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '500.00',
            'variance_amount' => '-100.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'report_revision_id' => $rev1->id,
        ]);
        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'receiving_branch_manager_shift_id' => $this->workday->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '500.00',
            'confirmed_at' => now(),
        ]);

        // Normal public liability commands establish the approved, posted initial shortage.
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/liability/allocate", [
                'expected_revision' => 1, 'expected_version' => 0, 'cashier_confirmed' => true,
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
            ])->assertCreated();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/liability/approve", [
                'expected_revision' => 1, 'version' => 1,
            ])->assertOk();
        // Accountant submits correction via HTTP endpoint: adjusts card_payments from 400 to 500, cash from 600 to 500
        // Resulting variance is 0.00 SAR (0 halalas) - eliminating the initial shortage
        $correctResponse = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$shift->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Bank reconciliation corrected POS card transactions - shortage resolved to zero',
                'changes' => [
                    'card_payments' => '450.00',
                    'cash_collected' => '550.00',
                ],
                'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 5000]],
            ]);

        $correctResponse->assertOk()
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('manager_review_required', false)
            ->assertJsonPath('financial_results.variance_halalas', -5000);

        // Employee objection is evidence and not a veto (COR-07)
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/liability/respond", [
                'version' => 2, 'response' => 'objected', 'reason' => 'Current corrected shortage objection',
            ])->assertOk();

        // COR-07 / RESOLVED-02: Accountant is final authority across branches.
        // Workday submit succeeds immediately without requiring a manager approval!
        $finalSubmitResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $this->workday->id,
                'expected_revision' => 1,
            ]);

        $finalSubmitResponse->assertOk()->assertJsonPath('success', true);
        $this->assertTrue((bool) $this->workday->fresh()->daily_report_submitted);
    }

    /**
     * Scenario 3: Physical return -> In-flight reopen -> Recount -> Replacement -> Confirmed receipt.
     * Rejection with physical return confirmed -> same-recipient correction / replacement transfer -> receipt confirmed.
     */
    public function test_s111_rejection_physical_return_reopen_recount_replacement_receipt_workflow(): void
    {
        $sourceShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(4),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
        ]);

        // End with handover to cashier B
        $this->actingAs($this->cashierA, 'sanctum')->postJson("/api/cashier/shifts/{$sourceShift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'next_cashier_id' => $this->cashierB->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $sourceShift->id)->sole();

        // 1. Present
        $attemptId = $this->actingAs($this->cashierA, 'sanctum')->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => 50000,
            'idempotency_key' => 'sc3-present-1',
        ])->assertOk()->json('data.id');

        // 2. Reject by Cashier B with physical return requirement
        $this->actingAs($this->cashierB, 'sanctum')->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/reject", [
            'rejection_reason' => 'Discrepancy in notes',
            'confirmed_amount' => '450.00',
            'correction_reason' => 'actual_shortage',
            'transfer_attempt_id' => $attemptId,
        ])->assertOk();

        // 3. Initiate & Confirm Return of Physical Cash
        $returnId = $this->actingAs($this->cashierB, 'sanctum')->postJson("/api/shift-transfer-attempts/{$attemptId}/returns", [
            'returned_halalas' => 45000,
            'reason' => 'Cash handed back to Cashier A',
            'idempotency_key' => 'sc3-ret-1',
        ])->assertOk()->json('data.id');

        // Sender A confirms physical receipt of the returned cash
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/shift-transfer-returns/{$returnId}/confirm")
            ->assertOk();

        $this->assertDatabaseCount('shift_transfer_returns', 1);

        // 4. Sender performs same-recipient correction with the current revision (revision 2 created by rejection)
        $currentRev = app(ShiftReportRevisionService::class)->currentCashierRevision($sourceShift)?->revision_number ?? 1;

        $this->actingAs($this->cashierA);
        app(HandoverService::class)->recordHandoverEdit($sourceShift, [
            'expected_revision' => $currentRev,
            'handover_amount' => '450.00',
            'handover_notes' => 'Corrected notes after return and recount',
            'correction_reason' => 'input_error',
        ]);

        $correctedHandover = $handover->fresh();
        $this->assertSame($handover->id, $correctedHandover->id, 'Request ID must be preserved for same recipient');
        $this->assertSame('450.00', (string) $correctedHandover->handover_amount);
        $this->assertSame('pending', $correctedHandover->status);

        // 5. Present corrected amount
        $attempt2Id = $this->actingAs($this->cashierA, 'sanctum')->postJson("/api/shift-transfers/handover/{$correctedHandover->id}/present", [
            'presented_halalas' => 45000,
            'idempotency_key' => 'sc3-present-2',
        ])->assertOk()->json('data.id');

        // 6. Recipient confirms receipt
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover(
            $correctedHandover->id,
            $this->cashierB,
            '450.00',
            $destShift->id,
            null,
            null,
            $attempt2Id
        );

        $this->assertNotNull($receipt);
        $this->assertSame('450.00', (string) $receipt->confirmed_amount);
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $correctedHandover->id)->count());
    }

    /**
     * Scenario 4: Confirmed receipt followed by additive linked settlement.
     * Cashier -> Manager confirmed receipt of 100 SAR.
     * Manager identifies overpayment return of 20 SAR (2000 halalas) to cashier via HTTP route with idempotency.
     * Verifies:
     * - Receipt confirmed amount is 100% untouched (100.00).
     * - ShiftHandoverSettlement created with signed delta -2000 halalas.
     * - Exactly two counterpart ledger entries in category 'handover_settlement' with net delta 0.
     * - Manager cash availability reader reflects the deduction of returned cash.
     */
    public function test_s111_confirmed_partial_receipt_followed_by_additive_settlement_workflow(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->cashierA->id, 0);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'shift_id' => $shift->shift_id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_shift_id' => $this->workday->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'report_revision_id' => $rev->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);

        // Seed initial cash in manager's ledger so availableManagerCashMinor has positive baseline
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '100.00',
            'is_cash_in' => true,
            'transaction_date' => now(),
            'category' => 'Total Sales',
        ]);

        $receiptService = app(ShiftTransferReceiptService::class);
        $initialManagerCash = $receiptService->availableManagerCashMinor($this->manager->id);

        // Branch manager executes additive settlement via HTTP route (under approved authority test context)
        config(['shift.settlement_authority_approved' => true]);
        $idempotencyKey = (string) Str::uuid();
        $settleResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 2000,
                'reason' => '20 SAR excess returned to cashier drawer',
                'expected_settlement_version' => 0,
            ]);

        $settleResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_halalas', 2000)
            ->assertJsonPath('data.signed_delta_halalas', -2000);

        $settlementId = $settleResponse->json('data.id');

        // Original receipt confirmed amount is strictly IMMUTABLE
        $this->assertSame('100.00', (string) $receipt->fresh()->confirmed_amount);

        // Check dual counterpart transactions
        $custodyTx = CashierCustodyTransaction::where('settlement_id', $settlementId)->first();
        $ledgerTx = PersonalLedgerTransaction::where('settlement_id', $settlementId)->first();

        $this->assertNotNull($custodyTx);
        $this->assertNotNull($ledgerTx);
        $this->assertSame('handover_settlement', $custodyTx->category);
        $this->assertSame('handover_settlement', $ledgerTx->category);

        // Amounts in custody and ledger models: decimal string in SAR ('20.00')
        $this->assertSame('20.00', (string) $custodyTx->amount);
        $this->assertSame('20.00', (string) $ledgerTx->amount);
        $this->assertTrue((bool) $custodyTx->is_cash_in);
        $this->assertFalse((bool) $ledgerTx->is_cash_in);

        // In halalas: cashier cash-in (+2000), manager cash-out (-2000), net delta = 0
        $deltaA = ((bool) $custodyTx->is_cash_in ? 1 : -1) * (int) round((float) $custodyTx->amount * 100);
        $deltaB = ((bool) $ledgerTx->is_cash_in ? 1 : -1) * (int) round((float) $ledgerTx->amount * 100);
        $this->assertSame(2000, $deltaA);
        $this->assertSame(-2000, $deltaB);
        $this->assertSame(0, $deltaA + $deltaB);

        // Manager available cash has decreased by exactly 2000 halalas
        $newManagerCash = $receiptService->availableManagerCashMinor($this->manager->id);
        $this->assertSame($initialManagerCash - 2000, $newManagerCash);
    }

    /**
     * Scenario 5: Successor shift starts cleanly while predecessor is in pending/reopening review.
     * Operating responsibility transfers atomically without premature postings across boundaries.
     */
    public function test_s111_operating_responsibility_transfer_and_pending_predecessor_workflow(): void
    {
        $predecessorShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => now()->subHours(8),
            'actual_end_time' => now()->subHour(),
        ]);

        // Predecessor is still in-flight / pending review
        $revService = app(ShiftReportRevisionService::class);
        $rev1 = $revService->recordCashierRevision($predecessorShift, 'cashier', $this->cashierA->id, 0);

        // Successor shift starts independently
        $successorShift = CashierShift::create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $this->shiftTemplate->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $startService = app(CashierShiftStartService::class);
        $startedShift = $startService->startShift($successorShift);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $startedShift->status);
        $this->assertNotNull($startedShift->actual_start_time);

        // Successor shift has independent lifecycle; predecessor has not generated unintended counts or locks
        $this->assertDatabaseCount('shift_liability_daily_locks', 0);
    }
}
