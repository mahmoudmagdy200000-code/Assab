<?php

namespace Tests\Feature;

(static function () {
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
    $vendorAutoload = $projectRoot . '/vendor/autoload.php';
    if (! file_exists($vendorAutoload)) {
        return;
    }
    $loader = require $vendorAutoload;
    if (! ($loader instanceof \Composer\Autoload\ClassLoader)) {
        return;
    }

    $ref = new \ReflectionClass($loader);
    $propClassMap = $ref->getProperty('classMap');
    $propClassMap->setAccessible(true);
    $classMap = $propClassMap->getValue($loader);
    $sample = $classMap['App\\ApiResponse'] ?? reset($classMap);
    if ($sample) {
        $sampleNormalized = str_replace(['\\', '/'], '/', $sample);
        if (preg_match('#^(.*?)/(Modules|app|database|tests)/#i', $sampleNormalized, $m)) {
            $rawVendorBase = $m[1];
            $canonicalVendorBase = realpath($rawVendorBase) ? str_replace('\\', '/', realpath($rawVendorBase)) : $rawVendorBase;
            if (strcasecmp($canonicalVendorBase, $projectRoot) !== 0) {
                $newClassMap = [];
                foreach ($classMap as $class => $path) {
                    $norm = str_replace(['\\', '/'], '/', $path);
                    if (str_starts_with($norm, $rawVendorBase . '/')) {
                        $newClassMap[$class] = $projectRoot . substr($norm, strlen($rawVendorBase));
                    } else {
                        $newClassMap[$class] = $path;
                    }
                }
                $propClassMap->setValue($loader, $newClassMap);

                $propPrefixDirs = $ref->getProperty('prefixDirsPsr4');
                $propPrefixDirs->setAccessible(true);
                $prefixDirs = $propPrefixDirs->getValue($loader);
                $newPrefixDirs = [];
                foreach ($prefixDirs as $prefix => $dirs) {
                    $newDirs = [];
                    foreach ($dirs as $dir) {
                        $norm = str_replace(['\\', '/'], '/', $dir);
                        if (str_starts_with($norm, $rawVendorBase . '/')) {
                            $newDirs[] = $projectRoot . substr($norm, strlen($rawVendorBase));
                        } else {
                            $newDirs[] = $dir;
                        }
                    }
                    $newPrefixDirs[$prefix] = $newDirs;
                }
                $propPrefixDirs->setValue($loader, $newPrefixDirs);
            }
        }
    }
})();

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift as ShiftTemplate;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftLiabilityShare;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * BD-3 / SHF Report Revision and Allocation Version.
 *
 * Verifies explicit exposure of:
 * 1. reportRevision: The confirmed shift report revision number (from ShiftReportAggregate).
 * 2. allocationVersion: The shortage liability allocation version (from ShiftLiabilityAllocation).
 *
 * Enforces strict separation between report revision and liability version,
 * tests Accountant Correction API validation (expected_revision, expected_allocation_version),
 * and confirms that rejected stale requests produce zero financial side-effects.
 */
class ShiftReportRevisionAndAllocationVersionTest extends TestCase
{
    use RefreshDatabase;

    protected AsabCompany $company;
    protected AsabBrand $brandA;
    protected AsabBrand $brandB;
    protected Branch $branchA;
    protected Branch $branchB;
    protected AsabUser $head;
    protected AsabUser $accountantA;
    protected AsabUser $accountantB;
    protected Cashier $cashierA;
    protected BranchManager $managerA;
    protected BranchManagerShift $workdayA;
    protected CashierShift $cashierShiftA;
    protected AdminShift $adminShiftA;
    protected Employee $activeEmployeeA;
    protected Employee $foreignEmployeeB;
    protected ShiftReportRevisionService $revisionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Revision Test Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brandA = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand A',
            'status' => 'active',
            'sub_status' => 'active',
        ]);

        $this->brandB = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand B',
            'status' => 'active',
            'sub_status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brandA->id,
            'name' => 'Branch A',
        ]);

        $this->branchB = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brandB->id,
            'name' => 'Branch B',
        ]);

        $this->head = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Head User',
            'email' => 'head@rev.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->head->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        $this->accountantA = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant A',
            'email' => 'accountantA@rev.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountantA->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branchA->id],
        ]);

        $this->accountantB = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant B',
            'email' => 'accountantB@rev.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountantB->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branchB->id],
        ]);

        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Ahmed Cashier',
        ]);

        $this->managerA = BranchManager::where('branch_id', $this->branchA->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerA->update(['name' => 'Mahmoud Manager']);

        $this->workdayA = BranchManagerShift::create([
            'branch_manager_id' => $this->managerA->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => today(),
            'status' => 'completed',
            'total_sales' => '100.00',
            'cash_collected' => '40.00',
            'card_payments' => '40.00',
            'aggregator_payments' => '0.00',
            'variance' => '-20.00',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $templateA = ShiftTemplate::factory()->create(['branch_id' => $this->branchA->id]);

        $this->cashierShiftA = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $templateA->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '40.00',
            'closing_balance' => '40.00',
            'variance' => '-20.00',
            'status' => 'in_progress',
        ]));

        $this->adminShiftA = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $this->cashierShiftA->id,
            'name' => 'Morning Shift A',
            'role' => 'cashier',
            'shift_date' => today(),
            'status' => 'pending_review',
            'sales_amount' => 10000,
            'cash_actual' => 4000,
            'cash_expected' => 6000,
            'variance' => -2000,
            'cash_count_state' => 'counted',
        ]);

        $this->activeEmployeeA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'name' => 'Youssef Barista',
            'emp_number' => 'EMP-001',
            'role' => 'باريستا',
            'status' => 'active',
        ]);

        $this->foreignEmployeeB = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'name' => 'Foreign Employee B',
            'emp_number' => 'EMP-B01',
            'role' => 'عامل فرع ب',
            'status' => 'active',
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
    }

    private function createShiftOperation(array $payloadOverride = []): Operation
    {
        return Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $this->adminShiftA->id,
            'amount' => 10000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => array_merge([
                'shiftId' => $this->adminShiftA->id,
                'legacyShiftId' => $this->cashierShiftA->id,
                'salesHalalas' => 10000,
                'cardTotalHalalas' => 4000,
                'aggregatorTotalsHalalas' => 0,
                'openingFloatHalalas' => 1000,
                'cashExpectedHalalas' => 6000,
                'cashActualHalalas' => 4000,
                'varianceHalalas' => -2000,
                'cashCountState' => 'counted',
                'pkg6Review' => [
                    'phase' => 'accountant_review',
                    'status' => 'pending',
                ],
            ], $payloadOverride),
        ]);
    }

    /**
     * Test 1: SHF operation read exposes reportRevision and allocationVersion distinctly.
     */
    public function test_shift_operation_read_exposes_report_revision_and_allocation_version_distinctly(): void
    {
        $op = $this->createShiftOperation();

        // 1. Setup report revision 1
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 4000,
            'expected_halalas' => 6000,
            'variance_halalas' => -2000,
        ]);

        // 2. Setup liability allocation version 1
        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'reason' => 'عجز وردية مبدئي',
            'manager_approval_status' => 'pending',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        // Check report revision
        $this->assertSame(1, $response->json('reportRevision'), 'reportRevision must expose current_revision_number');
        $this->assertSame(1, $response->json('payload.reportRevision'));

        // Check allocation version
        $this->assertSame(1, $response->json('allocationVersion'), 'allocationVersion must expose shortage allocation version');
        $this->assertSame(1, $response->json('payload.allocationVersion'));

        // Check liability.version
        $this->assertSame(1, $response->json('liability.version'));
        $this->assertSame(1, $response->json('payload.liability.version'));
        $this->assertSame('pending', $response->json('liability.managerApprovalStatus'));
    }

    /**
     * Test 2: Shift operation with report revision but zero shortage has allocationVersion = null and liability = null.
     */
    public function test_shift_operation_read_with_report_revision_but_no_liability_allocation(): void
    {
        $op = $this->createShiftOperation();

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000,
            'expected_halalas' => 6000,
            'variance_halalas' => 0,
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame(1, $response->json('reportRevision'));
        $this->assertNull($response->json('allocationVersion'));
        $this->assertNull($response->json('liability'));

        $this->assertSame(1, $response->json('payload.reportRevision'));
        $this->assertNull($response->json('payload.allocationVersion'));
        $this->assertNull($response->json('payload.liability'));
    }

    /**
     * Test 3: Uncounted / historical shift without report aggregate returns null without fabricating defaults.
     */
    public function test_shift_operation_read_without_evidence_returns_null_for_both_versions(): void
    {
        // Standalone operation without report aggregate or allocation
        $op = $this->createShiftOperation(['pkg6Review' => null]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertNull($response->json('reportRevision'), 'Must be null when no evidence aggregate exists');
        $this->assertNull($response->json('allocationVersion'), 'Must be null when no allocation exists');
        $this->assertNull($response->json('liability'));
    }

    /**
     * Test 4: Accountant Correction with current versions succeeds and updates both versions.
     */
    public function test_accountant_correction_with_current_versions_succeeds_and_updates_both_versions(): void
    {
        $op = $this->createShiftOperation();

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 4000,
            'expected_halalas' => 6000,
            'variance_halalas' => -2000,
        ]);

        $alloc1 = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'reason' => 'عجز مبدئي',
            'manager_approval_status' => 'pending',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $alloc1->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'branch_manager_shift_id' => $this->workdayA->id,
            'report_revision' => (string) $rev1->id,
            'allocation_version' => 1,
            'locked_by_id' => $this->managerA->id,
            'locked_at' => now(),
        ]);

        // Verify initial read: reportRevision = 1, allocationVersion = 1
        $initialRead = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();
        $this->assertSame(1, $initialRead->json('reportRevision'));
        $this->assertSame(1, $initialRead->json('allocationVersion'));
        $this->assertSame(1, $initialRead->json('liability.version'));

        // Accountant submits correction with matching versions
        $idempotencyKey = (string) Str::uuid();
        $correctionPayload = [
            'expected_revision' => 1,
            'expected_allocation_version' => 1,
            'reason' => 'Audit adjustment on card settlements',
            'changes' => [
                'card_payments' => '30.00',
            ],
            'shortage_allocations' => [
                [
                    'type' => 'cashier',
                    'id' => $this->cashierA->id,
                    'amount' => 1800,
                ],
                [
                    'type' => 'employee',
                    'id' => $this->activeEmployeeA->id,
                    'amount' => 1200,
                ],
            ],
        ];

        $correctionResponse = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", $correctionPayload)
            ->assertOk();

        $this->assertSame(2, $correctionResponse->json('revision_number'));
        $this->assertSame(2, $correctionResponse->json('allocation_version'));

        // Verify updated read on operation: reportRevision = 2, allocationVersion = 2
        $updatedRead = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame(2, $updatedRead->json('reportRevision'));
        $this->assertSame(2, $updatedRead->json('allocationVersion'));
        $this->assertSame(2, $updatedRead->json('liability.version'));
        $this->assertSame('approved', $updatedRead->json('liability.managerApprovalStatus'));
        $this->assertCount(2, $updatedRead->json('liability.shares'));
    }

    /**
     * Test 5: Rejection of stale report revision returns 409 and causes ZERO mutations or ledger postings.
     */
    public function test_accountant_correction_with_stale_report_revision_is_rejected_without_mutation(): void
    {
        $op = $this->createShiftOperation();

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 4000,
            'expected_halalas' => 6000,
            'variance_halalas' => -2000,
        ]);

        $alloc1 = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'reason' => 'عجز مبدئي',
            'manager_approval_status' => 'pending',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $alloc1->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'branch_manager_shift_id' => $this->workdayA->id,
            'report_revision' => (string) $rev1->id,
            'allocation_version' => 1,
            'locked_by_id' => $this->managerA->id,
            'locked_at' => now(),
        ]);

        // Baseline counts before stale request
        $emCountBefore = EmployeeMovement::count();
        $pltCountBefore = PersonalLedgerTransaction::count();
        $cctCountBefore = CashierCustodyTransaction::count();
        $postingsBefore = DB::table('shift_liability_postings')->count();
        $revCountBefore = ShiftReportRevision::count();
        $allocCountBefore = ShiftLiabilityAllocation::count();
        $shareCountBefore = ShiftLiabilityShare::count();

        // Stale expected_revision: 99
        $stalePayload = [
            'expected_revision' => 99,
            'expected_allocation_version' => 1,
            'reason' => 'Stale report revision test',
            'changes' => ['card_payments' => '30.00'],
        ];

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", $stalePayload);

        $response->assertStatus(409);
        $this->assertSame('STALE_REPORT_REVISION', $response->json('error.code'));

        // Assert strictly ZERO financial side-effects
        $this->assertSame($emCountBefore, EmployeeMovement::count(), 'Zero EmployeeMovement records must be created on 409');
        $this->assertSame($pltCountBefore, PersonalLedgerTransaction::count(), 'Zero PersonalLedgerTransaction records must be created on 409');
        $this->assertSame($cctCountBefore, CashierCustodyTransaction::count(), 'Zero CashierCustodyTransaction records must be created on 409');
        $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count(), 'Zero shift liability postings on 409');
        $this->assertSame($revCountBefore, ShiftReportRevision::count(), 'ShiftReportRevision count must not change');
        $this->assertSame($allocCountBefore, ShiftLiabilityAllocation::count(), 'Allocation count must not change');
        $this->assertSame($shareCountBefore, ShiftLiabilityShare::count(), 'Share count must not change');
    }

    /**
     * Test 6: Rejection of stale allocation version returns 409 and causes ZERO mutations or ledger postings.
     */
    public function test_accountant_correction_with_stale_allocation_version_is_rejected_without_mutation(): void
    {
        $op = $this->createShiftOperation();

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 4000,
            'expected_halalas' => 6000,
            'variance_halalas' => -2000,
        ]);

        // Current allocation is at version 2
        $alloc2 = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 2,
            'report_revision' => '1',
            'variance_halalas' => -2000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->managerA->id,
            'reason' => 'توزيع محدث',
            'manager_approval_status' => 'approved',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $alloc2->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'branch_manager_shift_id' => $this->workdayA->id,
            'report_revision' => (string) $rev1->id,
            'allocation_version' => 2,
            'locked_by_id' => $this->managerA->id,
            'locked_at' => now(),
        ]);

        // Baseline counts before stale request
        $emCountBefore = EmployeeMovement::count();
        $pltCountBefore = PersonalLedgerTransaction::count();
        $cctCountBefore = CashierCustodyTransaction::count();
        $postingsBefore = DB::table('shift_liability_postings')->count();
        $revCountBefore = ShiftReportRevision::count();
        $allocCountBefore = ShiftLiabilityAllocation::count();

        // Stale expected_allocation_version: 1 (while current is 2)
        $stalePayload = [
            'expected_revision' => 1,
            'expected_allocation_version' => 1,
            'reason' => 'Stale allocation version test',
            'changes' => ['card_payments' => '30.00'],
            'shortage_allocations' => [
                ['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 3000],
            ],
        ];

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", $stalePayload);

        $response->assertStatus(409);
        $this->assertSame('STALE_ALLOCATION_VERSION', $response->json('error.code'));

        // Assert strictly ZERO financial side-effects
        $this->assertSame($emCountBefore, EmployeeMovement::count());
        $this->assertSame($pltCountBefore, PersonalLedgerTransaction::count());
        $this->assertSame($cctCountBefore, CashierCustodyTransaction::count());
        $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count());
        $this->assertSame($revCountBefore, ShiftReportRevision::count());
        $this->assertSame($allocCountBefore, ShiftLiabilityAllocation::count());
    }

    /**
     * Test 7: Branch isolation prevents cross-branch reading (404) and correction (403).
     */
    public function test_branch_isolation_blocks_unauthorized_accountant_read_and_correction(): void
    {
        $op = $this->createShiftOperation();

        // 1. Accountant B cannot read Branch A operation -> 404 Not Found
        $this->actingAs($this->accountantB, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertStatus(404);

        // 2. Accountant B cannot correct Branch A shift -> 403 Forbidden
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantB, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", [
                'expected_revision' => 1,
                'reason' => 'Unauthorized attempt across branches',
                'changes' => ['card_payments' => '30.00'],
            ])
            ->assertStatus(403);

        // 3. Head accountant views Branch A operation -> 200 OK
        $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();
    }
}
