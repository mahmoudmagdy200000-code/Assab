<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftDailyCloseScopeService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftDailyCloseScopeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private BranchManager $managerA;

    private BranchManager $managerB;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Shift $templateA;

    private Shift $templateB;

    private BranchManagerShift $workdayA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Daily Scope Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Daily Scope Brand',
            'status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->branchB = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);

        $this->managerA = BranchManager::factory()->create([
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->managerB = BranchManager::factory()->create([
            'branch_id' => $this->branchB->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'created_by' => $this->managerA->id,
        ]);
        $this->cashierB = Cashier::factory()->create([
            'branch_id' => $this->branchB->id,
            'created_by' => $this->managerB->id,
        ]);

        $this->templateA = Shift::factory()->create(['branch_id' => $this->branchA->id]);
        $this->templateB = Shift::factory()->create(['branch_id' => $this->branchB->id]);

        $this->workdayA = BranchManagerShift::create([
            'branch_manager_id' => $this->managerA->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);
    }

    public function test_workday_scope_is_server_derived_and_branch_scoped(): void
    {
        // Cashier shift in branch A (today)
        $shiftA = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);

        // Cashier shift in branch B (today)
        $shiftB = CashierShift::create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $this->templateB->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);

        // Scheduled shift in branch A not started
        $cashierA2 = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'created_by' => $this->managerA->id,
        ]);
        $shiftAUnstarted = CashierShift::create([
            'cashier_id' => $cashierA2->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => today(),
            'status' => 'not_started',
        ]);

        $service = app(ShiftDailyCloseScopeService::class);
        $scope = $service->resolve($this->workdayA);

        $this->assertContains($shiftA->id, $scope['cashier_shift_ids']);
        $this->assertNotContains($shiftB->id, $scope['cashier_shift_ids'], 'Cross-branch cashier shift must never be scoped.');
        $this->assertNotContains($shiftAUnstarted->id, $scope['cashier_shift_ids'], 'Unstarted scheduled cashier shift must not be in reports scope.');
    }

    public function test_review_replacement_manager_does_not_rediscover_consumed_same_day_report(): void
    {
        $source = CashierShift::create(['cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today(), 'status' => 'completed', 'total_sales' => '0.00']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($source) {
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $this->cashierA->id, 0);
            app(ShiftCashCountService::class)->record($source, $revision, 0, 0, 0, 0);
            app(ShiftReportRevisionService::class)->recordManagerRevision($this->workdayA, 'branch_manager', $this->managerA->id, 0);
        });
        $this->actingAs($this->managerA, 'sanctum')->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', ['expected_revision' => 1,
                'branch_manager_shift_id' => $this->workdayA->id])->assertOk();
        $this->managerA->update(['is_active' => false, 'status' => 'inactive']);
        $replacement = BranchManager::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true, 'status' => 'active']);
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $replacement->id, 'branch_id' => $this->branchA->id,
            'shift_date' => today()], ['status' => 'completed']);
        $scope = app(ShiftDailyCloseScopeService::class)->resolve($day);
        $this->assertNotContains($source->id, $scope['cashier_shift_ids']);
        $this->assertContains($source->id, app(ShiftDailyCloseScopeService::class)->resolve($this->workdayA)['cashier_shift_ids']);
    }

    public function test_unclosed_approved_carryover_older_than_seven_days_is_included(): void
    {
        $tenDaysAgo = today()->subDays(10);
        $oldShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => $tenDaysAgo,
            'status' => ShiftStatus::COMPLETED,
        ]);

        $oldHandover = CashierShiftHandover::create([
            'cashier_shift_id' => $oldShift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id,
            'handover_amount' => '100.00',
            'variance_amount' => '0.00',
            'handover_date' => $tenDaysAgo,
            'handover_time' => $tenDaysAgo,
            'status' => 'approved',
            'daily_closed_at' => null,
        ]);

        $service = app(ShiftDailyCloseScopeService::class);
        $scope = $service->resolve($this->workdayA);

        $this->assertContains($oldShift->id, $scope['cashier_shift_ids'], 'Carry-over older than 7 days must be included in cashier shifts.');
        $this->assertContains($oldHandover->id, $scope['handover_ids'], 'Carry-over handover must be included in handovers.');
    }

    public function test_unrelated_pending_transfer_does_not_block_workday(): void
    {
        // Branch A cashier shift with approved handover & confirmed receipt
        $shiftA = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);
        $revisionA = app(ShiftReportRevisionService::class)->recordCashierRevision($shiftA, 'cashier', $this->cashierA->id, 0);
        app(ShiftCashCountService::class)->record($shiftA, $revisionA, 10000, 0, 0, 10000);

        $handoverA = CashierShiftHandover::create([
            'cashier_shift_id' => $shiftA->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id,
            'handover_amount' => '100.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'daily_closed_at' => null,
        ]);
        $receiptA = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handoverA->id,
            'receiving_branch_manager_id' => $this->managerA->id,
            'receiving_branch_manager_shift_id' => $this->workdayA->id,
            'report_revision_id' => $revisionA->id,
            'confirmed_amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        // Branch B has a completely unrelated pending handover
        $shiftB = CashierShift::create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $this->templateB->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);
        $handoverB = CashierShiftHandover::create([
            'cashier_shift_id' => $shiftB->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerB->id,
            'handover_amount' => '50.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'pending',
            'daily_closed_at' => null,
        ]);

        $evidenceSource = app(\Modules\Shift\Liability\CashCountLiabilityEvidence::class);
        $evidence = $evidenceSource->dailyClose($this->workdayA->id);

        $this->assertArrayHasKey("handover:{$handoverA->id}", $evidence->requiredReceipts);
        $this->assertSame($receiptA->id, $evidence->requiredReceipts["handover:{$handoverA->id}"]);
        $this->assertArrayNotHasKey("handover:{$handoverB->id}", $evidence->requiredReceipts, 'Unrelated transfer must not appear in requiredReceipts.');
    }

    public function test_overnight_report_uses_workday_date_not_request_clock(): void
    {
        // Shift scheduled on workday date, but actual end time is early next morning
        $overnightShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
            'actual_end_time' => today()->addDay()->setHour(2)->setMinute(30),
        ]);

        $service = app(ShiftDailyCloseScopeService::class);
        $scope = $service->resolve($this->workdayA);

        $this->assertContains($overnightShift->id, $scope['cashier_shift_ids'], 'Overnight report must resolve by workday date, not request clock.');
    }

    public function test_f03_financial_report_creation_cannot_cross_submitted_workday_boundary(): void
    {
        $this->workdayA->update(['daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);
        $source = CashierShift::create([
            'cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today(), 'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now(),
        ]);
        try {
            app(\Modules\Shift\Services\ShiftEndService::class)->endShiftOnly($source, [
                'total_sales' => '10.00', 'cash_collected' => '10.00', 'card_payments' => '0.00', 'counted_cash' => '10.00',
            ], $this->cashierA);
            $this->fail('A new financial report crossed the closed workday boundary.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('REPORT_REOPEN_REQUIRED', $exception->getMessage());
        }
        $this->assertSame(0, \Modules\Shift\Models\ShiftReportRevision::count());
        $this->assertSame(ShiftStatus::IN_PROGRESS, $source->fresh()->status);
    }

    public function test_f03_prior_report_presented_today_is_required_membership(): void
    {
        $old = CashierShift::create(['cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today()->subDays(12), 'status' => ShiftStatus::COMPLETED]);
        $request = CashierShiftHandover::create(['cashier_shift_id' => $old->id,
            'handover_to_type' => 'branch_manager', 'handover_to_id' => $this->managerA->id,
            'handover_amount' => '10.00', 'handover_date' => today(), 'handover_time' => now(), 'status' => 'pending']);
        $scope = app(ShiftDailyCloseScopeService::class)->resolve($this->workdayA);
        $this->assertContains($old->id, $scope['cashier_shift_ids']);
        $this->assertContains($request->id, $scope['handover_ids']);
    }

    public function test_f03_unrelated_prior_same_branch_report_is_not_membership(): void
    {
        $old = CashierShift::create([
            'cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today()->subDay(), 'status' => ShiftStatus::COMPLETED,
        ]);
        $scope = app(ShiftDailyCloseScopeService::class)->resolve($this->workdayA);
        $this->assertNotContains($old->id, $scope['cashier_shift_ids']);
    }

    public function test_f03_previously_consumed_carryover_does_not_reenter_another_workday(): void
    {
        $old = CashierShift::create([
            'cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today()->subDay(), 'status' => ShiftStatus::COMPLETED,
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $old->id, 'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id, 'handover_amount' => '20.00',
            'handover_date' => today()->subDay(), 'handover_time' => now()->subDay(),
            'status' => 'approved', 'daily_closed_at' => now()->subDay(),
        ]);
        $scope = app(ShiftDailyCloseScopeService::class)->resolve($this->workdayA);
        $this->assertNotContains($old->id, $scope['cashier_shift_ids']);
        $this->assertNotContains($handover->id, $scope['handover_ids']);
    }

    public function test_f04_replacement_requires_reciprocal_source_identity(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashierA->id, 'shift_id' => $this->templateA->id,
            'shift_date' => today(), 'status' => ShiftStatus::COMPLETED,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->cashierA->id, 0);
        $old = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id, 'handover_to_type' => 'branch_manager', 'handover_to_id' => $this->managerA->id,
            'handover_amount' => '20.00', 'handover_date' => today(), 'handover_time' => now(),
            'status' => 'pending', 'cancelled_at' => now(), 'superseded_at' => now(),
        ]);
        $leaf = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id, 'handover_to_type' => 'branch_manager', 'handover_to_id' => $this->managerA->id,
            'handover_amount' => '20.00', 'handover_date' => today(), 'handover_time' => now(), 'status' => 'approved',
        ]);
        $old->update(['replacement_request_id' => $leaf->id]);
        CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $leaf->id, 'receiving_branch_manager_id' => $this->managerA->id,
            'receiving_branch_manager_shift_id' => $this->workdayA->id, 'report_revision_id' => $revision->id,
            'confirmed_amount' => '20.00', 'confirmed_at' => now(),
        ]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REPLACEMENT_CHAIN_LINEAGE_MISMATCH');
        app(ShiftDailyCloseScopeService::class)->resolveHandoverReceiptId($old);
    }

    public function test_reassigned_report_and_replacement_chain_are_not_silently_omitted(): void
    {
        // Reassigned shift
        $reassignedShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->templateA->id,
            'shift_date' => today(),
            'status' => ShiftStatus::REASSIGNED,
        ]);

        $service = app(ShiftDailyCloseScopeService::class);
        $scope = $service->resolve($this->workdayA);

        $this->assertContains($reassignedShift->id, $scope['cashier_shift_ids'], 'Reassigned shift with report must be included in daily close scope.');

        // Replacement chain: handover 1 superseded by handover 2
        $handover1 = CashierShiftHandover::create([
            'cashier_shift_id' => $reassignedShift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id,
            'handover_amount' => '60.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'pending',
            'cancelled_at' => now(),
            'superseded_at' => now(),
        ]);

        $handover2 = CashierShiftHandover::create([
            'cashier_shift_id' => $reassignedShift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id,
            'handover_amount' => '60.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'supersedes_id' => $handover1->id,
        ]);
        $handover1->update(['replacement_request_id' => $handover2->id]);

        $rev2 = app(ShiftReportRevisionService::class)->recordCashierRevision($reassignedShift, 'cashier', $this->cashierA->id, 0);

        // Handover 2 has receipt
        $receipt2 = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover2->id,
            'receiving_branch_manager_id' => $this->managerA->id,
            'receiving_branch_manager_shift_id' => $this->workdayA->id,
            'report_revision_id' => $rev2->id,
            'confirmed_amount' => '60.00',
            'confirmed_at' => now(),
        ]);

        $resolvedReceipt = $service->resolveHandoverReceiptId($handover1);
        $this->assertSame($receipt2->id, $resolvedReceipt, 'Chain must resolve to replacement receipt ID.');

        // Test cycle detection
        $handover2->update(['replacement_request_id' => $handover1->id]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REPLACEMENT_CHAIN_CYCLE_DETECTED');
        $service->resolveHandoverReceiptId($handover1);
    }
}
