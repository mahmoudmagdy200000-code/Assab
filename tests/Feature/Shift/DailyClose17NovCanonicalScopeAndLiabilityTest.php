<?php

namespace Tests\Feature\Shift;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Models\BranchAggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityShare;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftDailyCloseScopeService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class DailyClose17NovCanonicalScopeAndLiabilityTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $managerCashier;

    private Cashier $cashier;

    private Cashier $secondCashier;

    private Shift $shiftTemplate;

    private Shift $nightShiftTemplate;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Asia/Riyadh');

        $this->company = AsabCompany::create([
            'name' => 'Assab Demo Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Assab Demo Brand',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'Assab Demo Branch 1116',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'name' => 'Demo Manager',
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->managerCashier = app(CashierService::class)
            ->ensureBranchManagerCashier($this->manager->id, $this->branch->id, $this->manager);
        $this->cashier = Cashier::factory()->create([
            'name' => 'Regular Cashier A',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->secondCashier = Cashier::factory()->create([
            'name' => 'Regular Cashier B',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Evening Shift',
            'start_time' => '14:00:00',
            'end_time' => '23:00:00',
        ]);
        $this->nightShiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Overnight Shift',
            'start_time' => '23:00:00',
            'end_time' => '03:00:00',
        ]);
        $this->aggregator = Aggregator::factory()->create(['name' => 'Jahez']);
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $this->aggregator->id,
            'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper to build a completed counted cashier shift with an approved manager handover + receipt.
     *
     * @return array{shift: CashierShift, handover: CashierShiftHandover, receipt: CashierShiftHandoverReceipt, revision: \Modules\Shift\Models\ShiftReportRevision, count: \Modules\Shift\Models\ShiftReportCashCount}
     */
    private function createCompletedCountedShiftWithApprovedHandover(
        string $shiftDate,
        string $handoverDate,
        Cashier $cashier,
        BranchManager $recipientManager,
        ?BranchManagerShift $receivingWorkday,
        string $totalSales,
        string $cashCollected,
        string $cardPayments,
        string $deliveryApps,
        string $handoverAmount,
        string $varianceAmount,
        ?Shift $template = null,
        ?string $actorType = null,
        ?string $actorId = null,
    ): array {
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id,
            'shift_id' => ($template ?? $this->shiftTemplate)->id,
            'shift_date' => $shiftDate,
            'status' => ShiftStatus::COMPLETED,
            'actual_start_time' => Carbon::parse($shiftDate.' 08:00:00'),
            'actual_end_time' => Carbon::parse($handoverDate.' 21:34:32'),
            'total_sales' => $totalSales,
            'cash_collected' => $cashCollected,
            'card_payments' => $cardPayments,
            'closing_balance' => $handoverAmount,
            'variance' => $varianceAmount,
        ]);

        if ((float) $deliveryApps > 0) {
            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $shift->id,
                'aggregator_id' => $this->aggregator->id,
                'amount' => $deliveryApps,
            ]);
        }

        $grossHalalas = (int) round(((float) $totalSales) * 100);
        $cardsHalalas = (int) round(((float) $cardPayments) * 100);
        $appsHalalas = (int) round(((float) $deliveryApps) * 100);
        $countedHalalas = (int) round(((float) $handoverAmount) * 100);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision(
            $shift,
            $actorType ?? 'cashier',
            $actorId ?? $cashier->id,
            0
        );
        $count = app(ShiftCashCountService::class)->record(
            $shift,
            $revision,
            $grossHalalas,
            $cardsHalalas,
            $appsHalalas,
            $countedHalalas
        );

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $recipientManager->id,
            'handover_amount' => $handoverAmount,
            'variance_amount' => $varianceAmount,
            'status' => 'approved',
            'approved_by_id' => $recipientManager->id,
            'approved_by_type' => BranchManager::class,
            'approved_at' => Carbon::parse($handoverDate.' 21:38:00'),
            'report_revision_id' => $revision->id,
            'handover_date' => $handoverDate,
            'handover_time' => Carbon::parse($handoverDate.' 21:34:32'),
        ]);

        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_shift_id' => $receivingWorkday?->id,
            'receiving_branch_manager_id' => $recipientManager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => $handoverAmount,
            'confirmed_by_branch_manager_id' => $recipientManager->id,
            'confirmed_at' => Carbon::parse($handoverDate.' 21:38:00'),
        ]);

        return compact('shift', 'handover', 'receipt', 'revision', 'count');
    }

    private function createWorkday(
        string $shiftDate,
        ?BranchManager $manager = null,
        ?Branch $branch = null,
        array $attributes = [],
    ): BranchManagerShift {
        $mgr = $manager ?? $this->manager;
        $br = $branch ?? $this->branch;
        $workday = BranchManagerShift::create(array_merge([
            'branch_manager_id' => $mgr->id,
            'branch_id' => $br->id,
            'shift_date' => $shiftDate,
            'status' => 'completed',
            'actual_start_time' => Carbon::parse($shiftDate.' 08:00:00'),
            'actual_end_time' => Carbon::parse($shiftDate.' 23:00:00'),
        ], $attributes));

        app(ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $mgr->id, 0);

        return $workday;
    }

    private function createAllocation(
        CashierShift $shift,
        \Modules\Shift\Models\ShiftReportCashCount $count,
        string $createdByType,
        string $createdById,
        array $shares,
        string $managerApprovalStatus = 'pending',
        ?string $managerApprovedBy = null,
        bool $cashierConfirmed = true,
        int $version = 1,
        ?string $supersedesId = null,
    ): ShiftLiabilityAllocation {
        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $shift->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'version' => $version,
            'supersedes_allocation_id' => $supersedesId,
            'report_revision' => (string) ($count->evidence_revision_id ?? $count->counted_revision_id),
            'variance_halalas' => $count->variance_halalas,
            'created_by_type' => $createdByType,
            'created_by_id' => $createdById,
            'reason' => 'Shortage responsibility recorded',
            'cashier_confirmed_at' => $cashierConfirmed ? now() : null,
            'manager_approval_status' => $managerApprovalStatus,
            'manager_approved_by' => $managerApprovalStatus === 'approved' ? ($managerApprovedBy ?? $this->manager->id) : null,
            'manager_approved_at' => $managerApprovalStatus === 'approved' ? now() : null,
        ]);

        foreach ($shares as $share) {
            ShiftLiabilityShare::create([
                'allocation_id' => $allocation->id,
                'responsible_type' => $share['type'],
                'responsible_id' => $share['id'],
                'amount_halalas' => $share['amount'],
                'employee_response_status' => $share['response'] ?? 'pending',
                'employee_responded_at' => isset($share['response']) && $share['response'] !== 'pending' ? now() : null,
            ]);
        }

        return $allocation->load('shares');
    }

    // =========================================================================
    // B01 — Single current business-date shift (200 sales / 95 cash)
    // =========================================================================
    public function test_b01_single_current_date_shift_matches_across_history_detail_and_submit_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        $scope = app(ShiftDailyCloseScopeService::class)->resolve($workday);
        $this->assertSame([$nov17['shift']->id], $scope['cashier_shift_ids']);

        $historyRow = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $workday->id);

        $detail = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(200.0, $historyRow['financial_summary']['total_sales']);
        $this->assertEquals(95.0, $historyRow['financial_summary']['cash_collected']);
        $this->assertEquals(100.0, $historyRow['financial_summary']['card_payments']);
        $this->assertEquals(5.0, $historyRow['financial_summary']['aggregator_payments']);
        $this->assertEquals(-5.0, $historyRow['financial_summary']['total_variance']);
        $this->assertEquals(90.0, $historyRow['handoffs_summary']['total_amount']);

        $this->assertEquals($historyRow['financial_summary']['total_sales'], $detail['totals']['total_sales']);
        $this->assertEquals($historyRow['financial_summary']['cash_collected'], $detail['totals']['total_cash_collected']);
        $this->assertEquals($historyRow['financial_summary']['card_payments'], $detail['totals']['total_card_payments']);
        $this->assertEquals($historyRow['financial_summary']['aggregator_payments'], $detail['totals']['total_delivery_apps']);
        $this->assertEquals($historyRow['financial_summary']['total_variance'], $detail['totals']['total_variance']);
        $this->assertCount(1, $detail['cashier_breakdown']);
    }

    // =========================================================================
    // B02 — Previous-day unconsumed carryover (200/100) + current-day (200/95) => 400/195/200/5/190/-5
    // =========================================================================
    public function test_b02_unconsumed_prior_day_carryover_aligns_history_detail_and_submit_to_400_sales_195_cash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));

        // Simulate stale pre-carryover totals saved on branch_manager_shifts when shift ended early
        $workday = $this->createWorkday('2026-11-17', attributes: [
            'total_sales' => '200.00',
            'cash_collected' => '95.00',
            'card_payments' => '100.00',
            'aggregator_payments' => '5.00',
            'handover_amount' => '190.00',
            'closing_balance' => '190.00',
        ]);

        // Shift 1: Nov 16 shift (200 sales, 100 cash, 100 card, 0 apps, 100 handover, 0 variance), approved Nov 17
        $nov16 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-16',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
            template: $this->nightShiftTemplate,
        );

        // Shift 2: Nov 17 shift (200 sales, 95 cash, 100 card, 5 apps, 90 handover, -5 variance)
        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        $historyRow = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $workday->id);

        $detail = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');

        $shiftDetails = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/details?shift_id={$workday->id}")
            ->assertOk()
            ->json('data.shift');

        // Exact B02 arithmetic: 400 sales, 195 cash, 200 cards, 5 apps, 190 handover, -5 total variance
        foreach ([$historyRow['financial_summary'], $shiftDetails['financial_summary'], $detail['shift']['financial_summary']] as $summary) {
            $this->assertEquals(400.0, $summary['total_sales']);
            $this->assertEquals(195.0, $summary['cash_collected']);
            $this->assertEquals(200.0, $summary['card_payments']);
            $this->assertEquals(5.0, $summary['aggregator_payments']);
            $this->assertEquals(-5.0, $summary['total_variance']);
        }
        $this->assertEquals(400.0, $detail['totals']['total_sales']);
        $this->assertEquals(195.0, $detail['totals']['total_cash_collected']);
        $this->assertEquals(200.0, $detail['totals']['total_card_payments']);
        $this->assertEquals(5.0, $detail['totals']['total_delivery_apps']);
        $this->assertEquals(-5.0, $detail['totals']['total_variance']);
        $this->assertEquals(195.0, $detail['manager_summary']['expected_balance']);
        $this->assertEquals(190.0, $detail['manager_summary']['closing_balance']);
        $this->assertEquals(2, $historyRow['handoffs_summary']['approved']);
        $this->assertEquals(190.0, $historyRow['handoffs_summary']['total_amount']);
        $this->assertCount(2, $detail['cashier_breakdown']);

        // Stored shift_date was NOT mutated
        $this->assertSame('2026-11-16', $nov16['shift']->fresh()->shift_date->format('Y-m-d'));
        $this->assertSame('2026-11-17', $nov17['shift']->fresh()->shift_date->format('Y-m-d'));
    }

    // =========================================================================
    // B03 — Previous-day shift already consumed/locked elsewhere stays excluded (200/95, no double count)
    // =========================================================================
    public function test_b03_prior_day_shift_consumed_in_older_workday_is_excluded_from_current_workday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday16 = $this->createWorkday('2026-11-16', attributes: [
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => Carbon::parse('2026-11-16 23:55:00'),
        ]);
        $workday17 = $this->createWorkday('2026-11-17');

        $nov16 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-16',
            handoverDate: '2026-11-16',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday16,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
        );

        // Lock Shift 1 on Nov 16 (unreleased, active)
        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $nov16['shift']->id,
            'branch_manager_shift_id' => $workday16->id,
            'report_revision' => (string) $nov16['count']->counted_revision_id,
            'allocation_version' => null,
            'locked_by_id' => $this->manager->id,
            'locked_at' => Carbon::parse('2026-11-16 23:55:00'),
        ]);

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday17,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        $history = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $detail17 = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday17->id}")
            ->assertOk()
            ->json('data');

        // Nov 16 keeps its 200 / 100
        $this->assertEquals(200.0, $history[$workday16->id]['financial_summary']['total_sales']);
        $this->assertEquals(100.0, $history[$workday16->id]['financial_summary']['cash_collected']);

        // Nov 17 excludes Nov 16 and keeps ONLY 200 / 95 (zero double count)
        $this->assertEquals(200.0, $history[$workday17->id]['financial_summary']['total_sales']);
        $this->assertEquals(95.0, $history[$workday17->id]['financial_summary']['cash_collected']);
        $this->assertEquals(200.0, $detail17['totals']['total_sales']);
        $this->assertEquals(95.0, $detail17['totals']['total_cash_collected']);
        $this->assertCount(1, $detail17['cashier_breakdown']);
        $this->assertSame($nov17['shift']->id, $detail17['cashier_breakdown'][0]['cashier_shift_id']);
    }

    // =========================================================================
    // B04 — Released or superseded liability locks restore canonical carryover eligibility
    // =========================================================================
    public function test_b04_released_or_superseded_locks_follow_canonical_scope_membership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday16 = $this->createWorkday('2026-11-16');
        $workday17 = $this->createWorkday('2026-11-17');

        $nov16 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-16',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday17,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
        );
        $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday17,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        // Released historical lock on Nov 16 should NOT exclude Shift 1 from Nov 17
        $releasedLock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $nov16['shift']->id,
            'branch_manager_shift_id' => $workday16->id,
            'report_revision' => (string) $nov16['count']->counted_revision_id,
            'locked_by_id' => $this->manager->id,
            'locked_at' => Carbon::parse('2026-11-16 23:00:00'),
            'released_by_id' => $this->manager->id,
            'released_at' => Carbon::parse('2026-11-17 01:00:00'),
            'release_reason' => 'Reopened for correction',
        ]);

        // Superseded historical lock on Nov 16 should also NOT exclude Shift 1
        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $nov16['shift']->id,
            'branch_manager_shift_id' => $workday16->id,
            'report_revision' => (string) $nov16['count']->counted_revision_id,
            'locked_by_id' => $this->manager->id,
            'locked_at' => Carbon::parse('2026-11-17 02:00:00'),
            'superseded_at' => Carbon::parse('2026-11-17 03:00:00'),
        ]);

        $historyRow17 = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $workday17->id);

        $this->assertEquals(400.0, $historyRow17['financial_summary']['total_sales']);
        $this->assertEquals(195.0, $historyRow17['financial_summary']['cash_collected']);
        // Historical lock row remains intact as audit evidence
        $this->assertNotNull($releasedLock->fresh()->released_at);
    }

    // =========================================================================
    // B05 — Two manager workdays in same branch, cross-midnight window, Asia/Riyadh timezone
    // =========================================================================
    public function test_b05_two_manager_workdays_cross_midnight_and_timezone_no_leak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));

        // Two workdays in the same branch: prior manager (now archived/inactive) on Nov 16,
        // active successor manager on Nov 17 — plus cross-midnight shift window.
        $priorManager = BranchManager::factory()->create([
            'name' => 'Prior Manager',
            'branch_id' => $this->branch->id,
            'is_active' => false,
            'status' => 'inactive',
        ]);

        $workday16Prior = $this->createWorkday('2026-11-16', $priorManager);
        $workday17Active = $this->createWorkday('2026-11-17', $this->manager);

        // Overnight shift started Nov 16 23:30 Asia/Riyadh, handed over Nov 17 00:57 Asia/Riyadh to Prior Manager
        $overnightShift = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-16',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $priorManager,
            receivingWorkday: $workday16Prior,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
            template: $this->nightShiftTemplate,
        );

        // Evening shift on Nov 17 handed over to Active Manager
        $nov17Shift = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->secondCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday17Active,
            totalSales: '300.00',
            cashCollected: '150.00',
            cardPayments: '150.00',
            deliveryApps: '0.00',
            handoverAmount: '150.00',
            varianceAmount: '0.00',
        );

        // Overnight shift was handed over to Prior Manager (NOT Active Manager),
        // so Active Manager's Nov 17 workday must NOT include Prior Manager's Nov 16 shift!
        $scopeDay16 = app(ShiftDailyCloseScopeService::class)->resolve($workday16Prior);
        $scopeDay17 = app(ShiftDailyCloseScopeService::class)->resolve($workday17Active);

        $this->assertSame([$overnightShift['shift']->id], $scopeDay16['cashier_shift_ids']);
        $this->assertSame([$nov17Shift['shift']->id], $scopeDay17['cashier_shift_ids']);

        $history = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertEquals(200.0, $history[$workday16Prior->id]['financial_summary']['total_sales']);
        $this->assertEquals(300.0, $history[$workday17Active->id]['financial_summary']['total_sales']);

        // Submitting Nov 17 locks Nov 17 shift cleanly and reports Asia/Riyadh timezone without mutating shift_date
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday17Active->id,
                'expected_revision' => 1,
            ])
            ->assertOk();

        $detail17After = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday17Active->id}")
            ->assertOk()
            ->json('data');
        $this->assertEquals(300.0, $detail17After['totals']['total_sales']);
        $this->assertSame('Asia/Riyadh', $detail17After['timezone']);
        $this->assertSame('2026-11-16', $overnightShift['shift']->fresh()->shift_date->format('Y-m-d'));
        $this->assertSame('2026-11-17', $nov17Shift['shift']->fresh()->shift_date->format('Y-m-d'));
    }

    // =========================================================================
    // B06 — Different company/branch/manager on same date: zero cross-tenant leakage
    // =========================================================================
    public function test_b06_cross_tenant_branch_isolation_on_same_shift_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $otherCompany = AsabCompany::create([
            'name' => 'Other Tenant Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $otherBrand = AsabBrand::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Brand',
            'status' => 'active',
        ]);
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $otherCompany->id,
            'asab_brand_id' => $otherBrand->id,
        ]);
        $otherManager = BranchManager::factory()->create([
            'branch_id' => $otherBranch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $otherCashier = Cashier::factory()->create([
            'branch_id' => $otherBranch->id,
            'created_by' => $otherManager->id,
        ]);
        $otherTemplate = Shift::factory()->create(['branch_id' => $otherBranch->id]);

        $workdayA = $this->createWorkday('2026-11-17', $this->manager, $this->branch);
        $workdayB = $this->createWorkday('2026-11-17', $otherManager, $otherBranch);

        $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workdayA,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
        );
        $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $otherCashier,
            recipientManager: $otherManager,
            receivingWorkday: $workdayB,
            totalSales: '999.00',
            cashCollected: '999.00',
            cardPayments: '0.00',
            deliveryApps: '0.00',
            handoverAmount: '999.00',
            varianceAmount: '0.00',
            template: $otherTemplate,
        );

        $historyA = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $historyA);
        $this->assertSame($workdayA->id, $historyA[0]['id']);
        $this->assertEquals(200.0, $historyA[0]['financial_summary']['total_sales']);

        // Attempting to inspect foreign workday is rejected (403/404)
        $status = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workdayB->id}")
            ->getStatusCode();
        $this->assertContains($status, [403, 404]);
    }

    // =========================================================================
    // B07 — Pagination & multiple workdays with bounded query count
    // =========================================================================
    public function test_b07_paginated_history_computes_accurate_totals_with_bounded_query_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 22:00:00', 'Asia/Riyadh'));
        $workdays = [];

        for ($i = 0; $i < 6; $i++) {
            $date = Carbon::parse('2026-11-10')->addDays($i)->toDateString();
            $wd = $this->createWorkday($date, attributes: [
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => Carbon::parse($date.' 23:30:00'),
            ]);
            $workdays[$date] = $wd;

            $s1 = $this->createCompletedCountedShiftWithApprovedHandover(
                shiftDate: $date,
                handoverDate: $date,
                cashier: $this->cashier,
                recipientManager: $this->manager,
                receivingWorkday: $wd,
                totalSales: (string) (100 + ($i * 10)).'.00',
                cashCollected: '50.00',
                cardPayments: (string) (50 + ($i * 10)).'.00',
                deliveryApps: '0.00',
                handoverAmount: '50.00',
                varianceAmount: '0.00',
            );
            $s2 = $this->createCompletedCountedShiftWithApprovedHandover(
                shiftDate: $date,
                handoverDate: $date,
                cashier: $this->secondCashier,
                recipientManager: $this->manager,
                receivingWorkday: $wd,
                totalSales: '100.00',
                cashCollected: '50.00',
                cardPayments: '50.00',
                deliveryApps: '0.00',
                handoverAmount: '50.00',
                varianceAmount: '0.00',
                template: $this->nightShiftTemplate,
            );
            foreach ([$s1, $s2] as $item) {
                ShiftLiabilityDailyLock::create([
                    'cashier_shift_id' => $item['shift']->id,
                    'branch_manager_shift_id' => $wd->id,
                    'report_revision' => (string) $item['count']->counted_revision_id,
                    'locked_by_id' => $this->manager->id,
                    'locked_at' => Carbon::parse($date.' 23:30:00'),
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $page1 = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=4&page=1')
            ->assertOk();

        $queriesPage1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(4, $page1->json('data'));
        $this->assertSame(6, $page1->json('meta.total'));

        // Newest date on page 1 is 2026-11-15 (i=5 => 150 + 100 = 250 sales, 100 cash)
        $this->assertEquals(250.0, $page1->json('data.0.financial_summary.total_sales'));
        $this->assertEquals(100.0, $page1->json('data.0.financial_summary.cash_collected'));

        // Page 2 has remaining 2 workdays
        $page2 = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=4&page=2')
            ->assertOk();
        $this->assertCount(2, $page2->json('data'));
        // Oldest date is 2026-11-10 (i=0 => 100 + 100 = 200 sales, 100 cash)
        $this->assertEquals(200.0, $page2->json('data.1.financial_summary.total_sales'));

        // Bounded query count: batch loading prevents per-cashier-shift N+1
        $this->assertLessThan(60, $queriesPage1, "Expected bounded query count on paginated history, got {$queriesPage1}.");
    }

    // =========================================================================
    // L01 + L02 + L05 — Pending 5 SAR shortage blocks submit despite approved handover;
    // authorized manager approval unblocks with single ledger posting
    // =========================================================================
    public function test_l01_l02_l05_completed_handover_does_not_bypass_pending_liability_and_authorized_approval_unblocks_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov16 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-16',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '100.00',
            cardPayments: '100.00',
            deliveryApps: '0.00',
            handoverAmount: '100.00',
            varianceAmount: '0.00',
            template: $this->nightShiftTemplate,
        );
        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        // Pending 5 SAR shortage allocation (500 halalas)
        $allocation = $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'cashier',
            createdById: $this->cashier->id,
            shares: [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500]],
            managerApprovalStatus: 'pending',
            cashierConfirmed: true,
        );

        // L05: Handover is approved/completed, yet daily close exposes pending liability status
        $this->assertSame('approved', $nov17['handover']->fresh()->status);
        $detailBefore = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');
        $nov17Row = collect($detailBefore['cashier_breakdown'])->firstWhere('cashier_shift_id', $nov17['shift']->id);
        $this->assertSame('approved', $nov17Row['handover_status']);
        $this->assertSame('pending', $nov17Row['liability_approval_status']);
        $this->assertSame(1, $nov17Row['liability_allocation_version']);

        // L01: Submit daily close rejects with 409 LIABILITY_APPROVAL_REQUIRED, zero ledger postings, zero locks
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LIABILITY_APPROVAL_REQUIRED');

        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
        $this->assertSame(0, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());
        $this->assertSame(0, ShiftLiabilityDailyLock::count());
        // L05: Handover stays approved
        $this->assertSame('approved', $nov17['handover']->fresh()->status);

        // L02: Authorized manager approves via existing S1-07/S1-10 liability approve endpoint
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => $allocation->version,
                'expected_revision' => $nov17['revision']->revision_number,
                'explicit_self_share' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.manager_approval_status', 'approved');

        $approvedAllocation = $allocation->fresh();
        $this->assertSame('approved', $approvedAllocation->manager_approval_status);
        $this->assertSame((string) $this->manager->id, (string) $approvedAllocation->manager_approved_by);
        $this->assertNotNull($approvedAllocation->manager_approved_at);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());

        // Daily close detail now reflects liability_approval_status = approved
        $detailApproved = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');
        $nov17RowApproved = collect($detailApproved['cashier_breakdown'])->firstWhere('cashier_shift_id', $nov17['shift']->id);
        $this->assertSame('approved', $nov17RowApproved['liability_approval_status']);

        // Now submit daily close succeeds cleanly, locks both shifts, and does NOT duplicate ledger posting
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertOk();

        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
        $this->assertSame(2, ShiftLiabilityDailyLock::active()->where('branch_manager_shift_id', $workday->id)->count());
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());

        // Post-submit reads retain BOTH locked shifts (400 sales / 195 cash) even after daily_closed_at sweep
        $this->assertNotNull($nov16['handover']->fresh()->daily_closed_at);
        $detailPostSubmit = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');
        $this->assertEquals(400.0, $detailPostSubmit['totals']['total_sales']);
        $this->assertEquals(195.0, $detailPostSubmit['totals']['total_cash_collected']);
        $this->assertCount(2, $detailPostSubmit['cashier_breakdown']);
    }

    // =========================================================================
    // L03 — Prior v1 approved, latest revised v2 pending => still blocked
    // =========================================================================
    public function test_l03_prior_allocation_approved_but_latest_revised_allocation_pending_still_blocks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        // v1 approved and superseded
        $v1 = $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'cashier',
            createdById: $this->cashier->id,
            shares: [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500]],
            managerApprovalStatus: 'approved',
            cashierConfirmed: true,
            version: 1,
        );
        $v1->update(['superseded_at' => now()]);

        // v2 revised allocation still pending manager approval
        $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'branch_manager',
            createdById: $this->manager->id,
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 300],
                ['type' => 'cashier', 'id' => $this->secondCashier->id, 'amount' => 200],
            ],
            managerApprovalStatus: 'pending',
            cashierConfirmed: false,
            version: 2,
            supersedesId: $v1->id,
        );

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LIABILITY_APPROVAL_REQUIRED');

        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
    }

    // =========================================================================
    // L04 — Non-manager caller (and foreign-branch manager) rejected from approving liability
    // =========================================================================
    public function test_l04_non_manager_and_foreign_manager_cannot_approve_liability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        $allocation = $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'cashier',
            createdById: $this->cashier->id,
            shares: [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500]],
            managerApprovalStatus: 'pending',
            cashierConfirmed: true,
        );

        // Cashier caller rejected with 403 ONLY_BRANCH_MANAGER
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => 1,
                'expected_revision' => $nov17['revision']->revision_number,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ONLY_BRANCH_MANAGER');

        // Foreign branch manager also rejected with 403
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $otherManager = BranchManager::factory()->create([
            'branch_id' => $otherBranch->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->actingAs($otherManager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => 1,
                'expected_revision' => $nov17['revision']->revision_number,
            ])
            ->assertStatus(403);

        // Zero state or ledger mutation
        $this->assertSame('pending', $allocation->fresh()->manager_approval_status);
        $this->assertSame(0, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());
    }

    // =========================================================================
    // L06 — New manager-as-own-cashier self-shift auto-approves at close transition with single posting
    // =========================================================================
    public function test_l06_new_manager_self_shift_auto_approves_only_at_close_transition_with_single_posting(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->managerCashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        app(CashierShiftStartService::class)->startShift($shift);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/end-with-handover", [
                'total_sales' => '200.00',
                'cash_collected' => '95.00',
                'card_payments' => '100.00',
                'aggregators' => [
                    ['aggregator_id' => $this->aggregator->id, 'amount' => '5.00'],
                ],
                'counted_cash' => '90.00',
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->manager->id,
                'handover_amount' => '90.00',
                'shortage_allocations' => [
                    [
                        'responsible_type' => 'branch_manager',
                        'responsible_id' => $this->manager->id,
                        'amount' => '5.00',
                    ],
                ],
            ])
            ->assertOk();

        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame('approved', $allocation->manager_approval_status);
        $this->assertSame((string) $this->manager->id, (string) $allocation->manager_approved_by);
        $this->assertNotNull($allocation->manager_approved_at);
        $this->assertNotNull($allocation->cashier_confirmed_at);
        $this->assertSame('accepted', $allocation->shares()->sole()->employee_response_status);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $shift->id)->count());

        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())
            ->firstOrFail();
        $workday->update(['status' => 'in_progress', 'actual_start_time' => now()->subHour()]);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/end', [
                'manager_workday_id' => $workday->id,
                'handover_timing' => 'today',
            ])
            ->assertOk();

        $rev = app(ShiftReportRevisionService::class)->currentManagerRevision($workday);
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => $rev->revision_number,
            ])
            ->assertOk();

        // No duplicate posting
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $shift->id)->count());
    }

    // =========================================================================
    // L07 — Historical self-shift recorded before PR fix stays pending until explicit approval
    // =========================================================================
    public function test_l07_historical_self_shift_pending_allocation_is_not_retroactively_approved_on_read_or_submit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->managerCashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
            actorType: 'branch_manager',
            actorId: $this->manager->id,
        );

        // Pre-PR-23 historical pending allocation where manager assigned 5 SAR shortage to self
        $allocation = $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'branch_manager',
            createdById: $this->manager->id,
            shares: [['type' => 'branch_manager', 'id' => $this->manager->id, 'amount' => 500]],
            managerApprovalStatus: 'pending',
            cashierConfirmed: true,
        );

        // Reading history & daily close does NOT mutate historical allocation
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
            ->assertOk();

        $detail = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/daily-close?manager_workday_id={$workday->id}")
            ->assertOk()
            ->json('data');

        $row = collect($detail['cashier_breakdown'])->firstWhere('cashier_shift_id', $nov17['shift']->id);
        $this->assertSame('pending', $row['liability_approval_status']);
        $this->assertTrue($row['liability_has_manager_self_share']);
        $this->assertSame('pending', $allocation->fresh()->manager_approval_status);

        // Submit daily close does NOT retroactively auto-approve; stays blocked with 409 LIABILITY_APPROVAL_REQUIRED
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LIABILITY_APPROVAL_REQUIRED');

        $this->assertSame('pending', $allocation->fresh()->manager_approval_status);

        // Approving without explicit_self_share fails closed for self-share safety
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => 1,
                'expected_revision' => $nov17['revision']->revision_number,
                'explicit_self_share' => false,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED');

        // Explicit authorized approval succeeds, reconciles ledger once, and unblocks submit
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => 1,
                'expected_revision' => $nov17['revision']->revision_number,
                'explicit_self_share' => true,
            ])
            ->assertOk();

        $this->assertSame('approved', $allocation->fresh()->manager_approval_status);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertOk();

        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->count());
    }

    // =========================================================================
    // L08 — Ordinary cashier deficit, colleague objection (BR-10), manager approval, and revision history
    // =========================================================================
    public function test_l08_ordinary_cashier_deficit_objection_and_report_revision_preserve_history_and_guards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 22:00:00', 'Asia/Riyadh'));
        $workday = $this->createWorkday('2026-11-17');

        $nov17 = $this->createCompletedCountedShiftWithApprovedHandover(
            shiftDate: '2026-11-17',
            handoverDate: '2026-11-17',
            cashier: $this->cashier,
            recipientManager: $this->manager,
            receivingWorkday: $workday,
            totalSales: '200.00',
            cashCollected: '95.00',
            cardPayments: '100.00',
            deliveryApps: '5.00',
            handoverAmount: '90.00',
            varianceAmount: '-5.00',
        );

        // Cashier allocates 3 SAR to self + 2 SAR to colleague
        $allocation = $this->createAllocation(
            shift: $nov17['shift'],
            count: $nov17['count'],
            createdByType: 'cashier',
            createdById: $this->cashier->id,
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 300],
                ['type' => 'cashier', 'id' => $this->secondCashier->id, 'amount' => 200],
            ],
            managerApprovalStatus: 'pending',
            cashierConfirmed: true,
        );

        // Colleague objects via shift-reports/{shift}/liability/respond (BR-10: recorded evidence, never blocks manager approval)
        $this->actingAs($this->secondCashier, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shift-reports/{$nov17['shift']->id}/liability/respond", [
                'version' => 1,
                'response' => 'objected',
                'reason' => 'I was not operating this register.',
            ])
            ->assertOk();

        $colleagueShare = $allocation->shares()->where('responsible_id', $this->secondCashier->id)->sole();
        $this->assertSame('objected', $colleagueShare->employee_response_status);
        $this->assertSame('I was not operating this register.', $colleagueShare->employee_response_reason);

        // Manager approves allocation (objection is preserved intact, not overwritten!)
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$nov17['shift']->id}/liability/approve", [
                'version' => 1,
                'expected_revision' => $nov17['revision']->revision_number,
            ])
            ->assertOk();

        $this->assertSame('objected', $colleagueShare->fresh()->employee_response_status);
        $this->assertSame('approved', $allocation->fresh()->manager_approval_status);
        // 1 posting per responsible employee share (300 + 200 = 500 halalas total)
        $postings = ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->get();
        $this->assertCount(2, $postings);
        $this->assertSame(500, (int) $postings->sum('target_halalas'));
        $this->assertSame(500, (int) $postings->sum('delta_halalas'));

        // Report correction increases revision -> requires fresh liability decision on new revision, preserving v1 history
        app(ShiftReportCorrectionService::class)->correctCashierReport(
            $nov17['shift'],
            $this->manager,
            ['card_payments' => '99.00', 'cash_collected' => '96.00'],
            $nov17['revision']->revision_number,
            'Re-verified card terminal slip',
            (string) Str::uuid(),
            [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 600]]
        );

        // Old v1 allocation is preserved as superseded history; new v2 is pending.
        // Submit first blocks on BRANCH_MANAGER_REVIEW_REQUIRED (pending report correction review),
        // and after approving the report correction still blocks on LIABILITY_APPROVAL_REQUIRED for v2!
        $this->assertNotNull($allocation->fresh()->superseded_at);
        $v2 = ShiftLiabilityAllocation::where('cashier_shift_id', $nov17['shift']->id)->where('version', 2)->sole();
        $this->assertSame('pending', $v2->manager_approval_status);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BRANCH_MANAGER_REVIEW_REQUIRED');

        // Approving the report correction with explicit expected_allocation_version=2
        // approves v2 liability, reconciles the net delta to 600 halalas, and preserves v1 + objection history!
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/workday/shifts/{$nov17['shift']->id}/report-correction/approve", [
                'expected_revision' => 2,
                'expected_allocation_version' => 2,
                'reason' => 'Confirmed updated shortage allocation',
            ])
            ->assertOk();

        $this->assertSame('approved', $v2->fresh()->manager_approval_status);
        $this->assertSame('objected', $colleagueShare->fresh()->employee_response_status);
        // Net ledger delta across all postings equals the revised 600 halalas shortage (no duplicate charge)
        $this->assertSame(
            600,
            (int) ShiftLiabilityPosting::where('cashier_shift_id', $nov17['shift']->id)->sum('delta_halalas')
        );

        // Now daily close submit succeeds cleanly with v2 locked
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertOk();
        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
    }
}
