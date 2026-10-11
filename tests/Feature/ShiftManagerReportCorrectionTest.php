<?php

namespace Tests\Feature;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportCorrectionNotification;
use Modules\Shift\Models\ShiftReportManagerReview;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftManagerReportCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private Branch $branch;

    private BranchManager $manager;

    private BranchManagerShift $managerShift;

    private Cashier $cashier;

    private Shift $shift;

    private CashierShift $cashierShift;

    private ShiftReportCorrectionService $correctionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->companyId,
        ]);

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->managerShift = BranchManagerShift::create([
            'branch_id' => $this->branch->id,
            'branch_manager_id' => $this->manager->id,
            'shift_date' => today(),
            'status' => 'active',
        ]);

        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->shift = Shift::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        $split = ShiftFinancialCalculator::calculateVatInclusiveSales('1000.00');
        $this->cashierShift = CashierShift::factory()->create([
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier->id,
            'shift_date' => today(),
            'total_sales' => '1000.00',
            'net_sales' => $split['net'],
            'vat_amount' => $split['vat'],
            'card_payments' => '500.00',
            'cash_collected' => '500.00',
            'status' => 'in_progress',
        ]);

        $this->correctionService = app(ShiftReportCorrectionService::class);
    }

    public function test_manager_report_correction_card_500_to_400_increases_expected_cash_and_decreases_variance_by_100(): void
    {
        // 1. Initial revision and cash count evidence
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        // Counted cash is 200.00 SAR (20000 halalas)
        // Expected cash was 1000.00 - 500.00 = 500.00 SAR (50000 halalas)
        // Variance was 200.00 - 500.00 = -300.00 SAR (-30000 halalas shortage)
        $initialCount = ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 100000,
            'cards_halalas' => 50000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 20000,
            'expected_halalas' => 50000,
            'variance_halalas' => -30000,
        ]);

        $this->cashierShift->update([
            'closing_balance' => '200.00',
            'variance' => '-300.00',
        ]);

        // 2. Manager corrects card_payments from 500.00 to 400.00
        $rev2 = $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '400.00'],
            1,
            'Customer card charge corrected by manager',
            (string) Str::uuid()
        );

        $this->assertSame(2, $rev2->revision_number);

        // 3. Inspect updated count evidence
        $newCount = ShiftReportCashCount::where('report_revision_id', $rev2->id)->sole();

        // Counted cash held strictly constant
        $this->assertSame(20000, $newCount->counted_halalas);
        $this->assertSame($rev1->id, $newCount->counted_revision_id);
        $this->assertSame($rev2->id, $newCount->evidence_revision_id);

        // Expected cash increased by 100.00 SAR (+10000 halalas: 50000 -> 60000)
        $this->assertSame(60000, $newCount->expected_halalas);

        // Variance decreased by 100.00 SAR (-10000 halalas: -30000 -> -40000)
        $this->assertSame(-40000, $newCount->variance_halalas);

        // Verify projection on cashier_shifts
        $this->cashierShift->refresh();
        $this->assertSame('400.00', (string) $this->cashierShift->card_payments);
        $this->assertSame('-400.00', (string) $this->cashierShift->variance);

        // Verify audit rows in shift_report_corrections
        $audit = ShiftReportCorrection::where('report_aggregate_id', $rev2->report_aggregate_id)
            ->where('new_revision_id', $rev2->id)
            ->where('field_name', 'card_payments')
            ->sole();
        $this->assertSame('monetary_halalas', $audit->field_type);
        $this->assertSame(50000, $audit->old_halalas);
        $this->assertSame(40000, $audit->new_halalas);
        $this->assertSame('branch_manager', $audit->actor_type);
        $this->assertSame((string) $this->manager->id, (string) $audit->actor_id);
    }

    public function test_manager_report_correction_preserves_confirmed_receipt_and_opening(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        // Create confirmed handover receipt
        $receiptId = (string) Str::uuid();
        DB::table('cashier_shift_handover_receipts')->insert([
            'id' => $receiptId,
            'receiving_cashier_shift_id' => $this->cashierShift->id,
            'receiving_cashier_id' => $this->cashier->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '50.00',
            'confirmed_by_id' => $this->cashier->id,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialReceipt = DB::table('cashier_shift_handover_receipts')->where('id', $receiptId)->first();

        // Perform manager correction
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '450.00'],
            1,
            'Manager card correction',
            (string) Str::uuid()
        );

        $afterReceipt = DB::table('cashier_shift_handover_receipts')->where('id', $receiptId)->first();

        // Receipt row must be completely unchanged
        $this->assertEquals($initialReceipt, $afterReceipt);
    }

    public function test_attempt_to_mutate_confirmed_receipt_fails_confirmed_receipt_immutable(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '100.00',
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => '12:00:00',
            'report_revision_id' => $rev1->id,
            'confirmed_at' => now(),
        ]);

        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'report_revision_id' => $rev1->id,
            'receiving_branch_manager_shift_id' => $this->managerShift->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'confirmed_amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        // Attempting to edit an already confirmed handover fails with CONFIRMED_RECEIPT_IMMUTABLE
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('CONFIRMED_RECEIPT_IMMUTABLE');

        app(HandoverService::class)->recordHandoverEdit($this->cashierShift, [
            'expected_revision' => 1,
            'handover_amount' => '120.00',
            'handover_notes' => 'Attempt modify confirmed',
            'correction_reason' => 'input_error',
        ]);
    }

    public function test_manager_correction_records_history_with_actor_type_branch_manager(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '450.00'],
            1,
            'Manager card correction audit trace',
            (string) Str::uuid()
        );

        $history = CashierShiftHistory::where('cashier_shift_id', $this->cashierShift->id)
            ->where('action', 'report_corrected')
            ->sole();

        $this->assertSame('branch_manager', $history->performed_by_type);
        $this->assertSame((string) $this->manager->id, (string) $history->performed_by);
        $this->assertSame('Manager card correction audit trace', $history->notes);
    }

    public function test_manager_correction_dispatches_outbox_notification_to_report_owner_cashier(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        $rev = $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '420.00'],
            1,
            'Notify cashier of manager correction',
            (string) Str::uuid()
        );

        $notification = ShiftReportCorrectionNotification::where('report_revision_id', $rev->id)
            ->where('event_type', 'report_corrected')
            ->sole();

        $this->assertSame('cashier', $notification->recipient_type);
        $this->assertSame((string) $this->cashier->id, (string) $notification->recipient_id);
        $this->assertContains($notification->status, ['pending', 'dispatched']);

        $payload = json_encode($notification->payload);
        $this->assertStringContainsString('Notify cashier of manager correction', $payload);
    }

    public function test_manager_correction_does_not_set_cashier_confirmed_at_or_fake_cashier_reapproval(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        $this->assertNull($this->cashierShift->cashier_confirmed_at);

        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '430.00'],
            1,
            'Check cashier confirmed flag untouched',
            (string) Str::uuid()
        );

        $this->cashierShift->refresh();
        $this->assertNull($this->cashierShift->cashier_confirmed_at);
    }

    public function test_manager_correction_supersedes_allocation_and_leaves_no_active_unapproved_allocation(): void
    {
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        // Create an existing allocation
        $alloc = ShiftLiabilityAllocation::create([
            'company_id' => $this->companyId,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -30000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'approved',
            'manager_approved_at' => now(),
            'manager_approved_by' => $this->manager->id,
        ]);

        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '410.00'],
            1,
            'Supersede previous allocation',
            (string) Str::uuid()
        );

        $alloc->refresh();
        $this->assertNotNull($alloc->superseded_at);

        // Verify there is no active allocation left
        $activeAlloc = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)
            ->whereNull('superseded_at')
            ->first();
        $this->assertNull($activeAlloc);
    }

    public function test_manager_correction_does_not_silently_remove_accountant_required_review(): void
    {
        $rev1 = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);

        // Set accountant required review pointer on aggregate
        $aggregate = ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $this->cashierShift->id)
            ->sole();

        $review = ShiftReportManagerReview::create([
            'report_revision_id' => $rev1->id,
            'company_id' => $this->companyId,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'report_revision_number' => 1,
            'status' => 'pending',
            'variance_halalas' => -30000,
            'has_shortage' => true,
            'reason' => 'Accountant placed required review',
        ]);

        $aggregate->update([
            'required_manager_review_evidence_revision_id' => $rev1->id,
        ]);

        // Manager corrects the report
        $rev2 = $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->manager,
            ['card_payments' => '410.00'],
            1,
            'Manager correction while review is required',
            (string) Str::uuid()
        );

        $aggregate->refresh();
        // The aggregate pointer must NOT be removed silently!
        $this->assertSame($rev1->id, $aggregate->required_manager_review_evidence_revision_id);

        $review->refresh();
        $this->assertSame('pending', $review->status);
    }
}
