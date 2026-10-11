<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferAttemptService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftS111FinalCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Shift $template;

    private BranchManager $manager;

    private Cashier $senderCashier;

    private Cashier $recipientCashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Final Test Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Final Test Brand',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
        $this->senderCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->recipientCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->template = Shift::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_s111_final_03_physical_return_required_regressions_a_through_g(): void
    {
        $sourceShift = CashierShift::create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'closing_balance' => '500.00',
        ]);
        $destShift = CashierShift::create([
            'cashier_id' => $this->recipientCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->senderCashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '500.00',
            'is_cash_in' => true,
            'related_shift_id' => $sourceShift->id,
            'transaction_date' => now(),
        ]);

        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $this->senderCashier->id, 0);
        app(ShiftCashCountService::class)->record($sourceShift, $rev1, 50000, 0, 0, 50000);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->recipientCashier->id,
            'next_cashier_shift_id' => $destShift->id,
            'handover_amount' => '500.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $rev1->id,
        ]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'status' => 'pending',
            'manager_approval_status' => 'pending',
            'shift_date' => today(),
        ]);

        $attemptService = app(ShiftTransferAttemptService::class);
        $firstAttempt = $attemptService->present('handover', $handover->id, $this->senderCashier, 50000, 'first-attempt');

        // Recipient physically finds 480 and rejects via HTTP endpoint
        $this->actingAs($this->recipientCashier, 'sanctum')->postJson("/api/cashier/shifts/{$sourceShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480 physically',
            'confirmed_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
            'transfer_attempt_id' => $firstAttempt->id,
        ])->assertOk();

        // A. Present 500 -> recipient physically rejects with 480 retained -> correction attempt before return => blocked
        $blockedA = false;
        try {
            app(HandoverService::class)->recordHandoverEdit($sourceShift, [
                'expected_revision' => 1,
                'correction_reason' => 'actual_shortage',
                'handover_amount' => '480.00',
            ]);
        } catch (ConflictHttpException $e) {
            $blockedA = true;
            $this->assertSame('PHYSICAL_RETURN_REQUIRED', $e->getMessage());
        }
        $this->assertTrue($blockedA, 'Correction before physical return must be blocked with PHYSICAL_RETURN_REQUIRED.');

        // B. Recipient initiates return 480 but sender has not confirmed => correction blocked
        $returnRes = $this->actingAs($this->recipientCashier, 'sanctum')->postJson("/api/shift-transfer-attempts/{$firstAttempt->id}/returns", [
            'returned_halalas' => 48000,
            'reason' => 'Returning rejected cash',
            'idempotency_key' => 'ret-key',
        ]);
        $returnRes->assertOk();
        $returnId = $returnRes->json('data.id');

        $blockedB = false;
        try {
            app(HandoverService::class)->recordHandoverEdit($sourceShift, [
                'expected_revision' => 1,
                'correction_reason' => 'actual_shortage',
                'handover_amount' => '480.00',
            ]);
        } catch (ConflictHttpException $e) {
            $blockedB = true;
            $this->assertSame('PHYSICAL_RETURN_REQUIRED', $e->getMessage());
        }
        $this->assertTrue($blockedB, 'Correction while return is unconfirmed must be blocked with PHYSICAL_RETURN_REQUIRED.');

        // C. Sender confirms return 480 => correction of same request to 480 succeeds
        $this->actingAs($this->senderCashier, 'sanctum')->postJson("/api/shift-transfer-returns/{$returnId}/confirm")->assertOk();
        $this->assertSame(0, $attemptService->retained($firstAttempt));

        $updatedShift = app(HandoverService::class)->recordHandoverEdit($sourceShift, [
            'expected_revision' => 1,
            'correction_reason' => 'actual_shortage',
            'handover_amount' => '480.00',
        ]);
        $this->assertSame('480.00', (string) $handover->fresh()->handover_amount);
        $this->assertSame('pending', $handover->fresh()->status);

        // D. New presentation creates a new immutable attempt sequence
        $secondAttempt = $attemptService->present('handover', $handover->fresh()->id, $this->senderCashier, 48000, 'second-attempt');
        $this->assertNotSame($firstAttempt->id, $secondAttempt->id);
        $this->assertSame(2, $secondAttempt->sequence);
        $this->assertSame(48000, $secondAttempt->presented_halalas);

        // E. Original attempt/rejection/return remain immutable history
        $this->assertDatabaseHas('shift_transfer_attempts', ['id' => $firstAttempt->id, 'sequence' => 1, 'presented_halalas' => 50000]);
        $this->assertDatabaseHas('shift_transfer_rejection_evidence', ['transfer_attempt_id' => $firstAttempt->id, 'physical_halalas' => 48000]);
        $this->assertDatabaseHas('shift_transfer_returns', ['id' => $returnId, 'returned_halalas' => 48000]);

        // F. Recipient never receives a shortage liability for the 20 input difference
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $destShift->id)->count());

        // G. Different-recipient replacement continues to require confirmed physical return first
        $cashier3 = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');
        app(HandoverService::class)->recordHandoverEdit($sourceShift, [
            'expected_revision' => 2,
            'correction_reason' => 'input_error',
            'handover_amount' => '480.00',
            'handover_to_id' => $cashier3->id,
        ]);
    }

    public function test_s111_final_04_single_canonical_variance_truth(): void
    {
        // gross = 115, cards = 50, apps = 25, confirmed opening = 10, counted = 30
        // expected cash = 115 - 50 - 25 + 10 = 50
        // variance = 30 - 50 = -20 (shortage)
        $shift = CashierShift::create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => '115.00',
            'card_payments' => '50.00',
            'cash_collected' => '40.00',
            'opening_balance' => '10.00',
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->senderCashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '40.00',
            'is_cash_in' => true,
            'related_shift_id' => $shift->id,
            'transaction_date' => now(),
        ]);

        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->senderCashier->id, 0);

        // Confirmed opening of 10.00 from incoming handover receipt
        $priorShift = CashierShift::create([
            'cashier_id' => $this->recipientCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);
        $incomingHandover = CashierShiftHandover::create([
            'cashier_shift_id' => $priorShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->senderCashier->id,
            'next_cashier_shift_id' => $shift->id,
            'handover_amount' => '10.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'report_revision_id' => $rev1->id,
        ]);
        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $incomingHandover->id,
            'receiving_cashier_id' => $this->senderCashier->id,
            'receiving_cashier_shift_id' => $shift->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '10.00',
            'confirmed_at' => now(),
        ]);

        // Delivery app breakdown = 25.00
        $aggregator = Aggregator::create([
            'name' => 'Jahez',
            'code' => 'JAHEZ',
            'is_active' => true,
        ]);
        ShiftSalesBreakdown::create([
            'cashier_shift_id' => $shift->id,
            'aggregator_id' => $aggregator->id,
            'amount' => '25.00',
        ]);

        // Record physical count: gross=11500, cards=5000, apps=2500, counted=3000
        $count = app(ShiftCashCountService::class)->record(
            $shift,
            $rev1,
            grossHalalas: 11500,
            cardsHalalas: 5000,
            appsHalalas: 2500,
            countedHalalas: 3000
        );

        // Verify count evidence stored figures
        $this->assertSame(5000, $count->expected_halalas); // 50.00 SAR
        $this->assertSame(-2000, $count->variance_halalas); // -20.00 SAR

        // 1. Authoritative financial variance from CashierShift model
        $this->assertEquals(-20.00, $shift->calculateVariance());
        $this->assertTrue($shift->hasVariance());

        // 2. Legacy channel difference is distinct from canonical physical count variance
        // legacy channel difference = total_sales - (cash_collected + card_payments + apps)
        // 115 - (40 + 50 + 25) = 0
        $this->assertEquals(0.00, $shift->calculateChannelDifference());
        $this->assertNotEquals($shift->calculateChannelDifference(), $shift->calculateVariance());

        // 3. HandoverService writes canonical expected_balance and variance
        $handover = app(HandoverService::class)->recordHandover($shift, [
            'handover_amount' => '20.00',
            'next_cashier_id' => $this->recipientCashier->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->recipientCashier->id,
        ]);
        $this->assertSame('50.00', (string) $shift->fresh()->expected_balance);
        $this->assertSame('-20.00', (string) $shift->fresh()->variance);

        // 4. Pending incoming rejected transfer does NOT create false surplus
        $pendingIncomingHandover = CashierShiftHandover::create([
            'cashier_shift_id' => $priorShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->senderCashier->id,
            'next_cashier_shift_id' => $shift->id,
            'handover_amount' => '100.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'rejected',
            'report_revision_id' => $rev1->id,
        ]);
        // Confirmed opening halalas remains 1000, not 11000
        $this->assertSame(1000, app(ShiftCashCountService::class)->confirmedOpeningHalalas($shift->id));
    }

    public function test_s111_final_05_cashier_correction_after_rejection_full_contract_regressions_a_through_g(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->senderCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => '100.00',
            'card_payments' => '20.00',
            'cash_collected' => '80.00',
            'closing_balance' => '80.00',
            'opening_balance' => '0.00',
        ]);

        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->senderCashier->id, 0);
        app(ShiftCashCountService::class)->record($shift, $rev1, 10000, 2000, 0, 8000);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->recipientCashier->id,
            'handover_amount' => '80.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'rejected',
            'report_revision_id' => $rev1->id,
        ]);
        $handoverStatus = ShiftHandoverStatus::create([
            'cashier_shift_id' => $shift->id,
            'status' => 'rejected',
            'manager_approval_status' => 'rejected',
            'rejection_count' => 1,
            'shift_date' => today(),
        ]);

        // A. Rejected report -> cashier changes cards + count + handover amount + shortage allocation
        // Total sales: 100.00, Card payments: 40.00, Counted: 40.00 => Expected: 100 - 40 = 60, Variance: 40 - 60 = -20 (shortage 2000h)
        $allocations = [
            [
                'responsible_type' => 'cashier',
                'responsible_id' => (string) $this->senderCashier->id,
                'amount' => '20.00',
            ],
        ];

        $updatedShift = app(HandoverService::class)->recordHandoverEdit($shift, [
            'expected_revision' => 1,
            'correction_reason' => 'actual_shortage',
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'counted_cash' => '40.00',
            'handover_amount' => '40.00',
            'shortage_allocations' => $allocations,
        ]);

        // B. One new report revision only (1 -> 2)
        $currentRev = app(ShiftReportRevisionService::class)->currentCashierRevision($shift);
        $this->assertSame(2, $currentRev->revision_number);
        $this->assertSame(2, ShiftReportRevision::where('report_aggregate_id', $currentRev->report_aggregate_id)->count());

        // C. Old revision preserved
        $this->assertDatabaseHas('shift_report_revisions', ['id' => $rev1->id, 'revision_number' => 1]);

        // D. Canonical variance recalculated correctly
        $newCount = app(ShiftCashCountService::class)->currentFor($shift->id);
        $this->assertSame(6000, $newCount->expected_halalas); // 60.00 SAR
        $this->assertSame(-2000, $newCount->variance_halalas); // -20.00 SAR
        $this->assertSame('-20.00', (string) $shift->fresh()->variance);

        // Verify shortage allocation created for 2000 halalas
        $alloc = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->whereNull('superseded_at')->firstOrFail();
        $this->assertSame(-2000, $alloc->variance_halalas);
        $this->assertSame(2000, (int) $alloc->shares->first()->amount_halalas);

        // E. Stale revision rejected
        $staleBlocked = false;
        try {
            app(HandoverService::class)->recordHandoverEdit($shift, [
                'expected_revision' => 1, // Stale! Current is 2
                'correction_reason' => 'actual_shortage',
                'handover_amount' => '40.00',
            ]);
        } catch (ConflictHttpException $e) {
            $staleBlocked = true;
            $this->assertSame('STALE_REPORT_REVISION', $e->getMessage());
        }
        $this->assertTrue($staleBlocked, 'Stale expected_revision must be rejected with STALE_REPORT_REVISION.');

        // F. Retry replays without duplicate revision or financial effects
        $idempotencyKey = (string) Str::uuid();
        $reqData = [
            'expected_revision' => 2,
            'correction_reason' => 'actual_shortage',
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'counted_cash' => '40.00',
            'handover_amount' => '40.00',
            'shortage_allocations' => $allocations,
        ];
        // Ensure status is rejected to allow second edit call for replay test
        $handover->fresh()->update(['status' => 'rejected']);
        $handoverStatus->fresh()->update(['status' => 'rejected', 'manager_approval_status' => 'rejected']);

        $res1 = $this->actingAs($this->senderCashier, 'sanctum')->postJson(
            "/api/cashier/shifts/{$shift->id}/handover/edit",
            $reqData,
            ['Idempotency-Key' => $idempotencyKey]
        );
        $res1->assertOk();
        $revNumberAfterFirst = app(ShiftReportRevisionService::class)->currentCashierRevision($shift)->revision_number;

        // Second call with same idempotency key
        $res2 = $this->actingAs($this->senderCashier, 'sanctum')->postJson(
            "/api/cashier/shifts/{$shift->id}/handover/edit",
            $reqData,
            ['Idempotency-Key' => $idempotencyKey]
        );
        $res2->assertOk();
        $this->assertSame($res1->json(), $res2->json());
        $this->assertSame($revNumberAfterFirst, app(ShiftReportRevisionService::class)->currentCashierRevision($shift)->revision_number);

        // G. Confirmed receipt cannot be edited
        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_id' => $this->recipientCashier->id,
            'report_revision_id' => $currentRev->id,
            'confirmed_amount' => '40.00',
            'confirmed_at' => now(),
        ]);
        $handover->update(['status' => 'approved']);

        $receiptBlocked = false;
        try {
            app(HandoverService::class)->recordHandoverEdit($shift, [
                'expected_revision' => $revNumberAfterFirst,
                'correction_reason' => 'input_error',
                'handover_amount' => '30.00',
            ]);
        } catch (ConflictHttpException $e) {
            $receiptBlocked = true;
            $this->assertSame('CONFIRMED_RECEIPT_IMMUTABLE', $e->getMessage());
        }
        $this->assertTrue($receiptBlocked, 'Confirmed receipt must be immutable.');
    }
}
