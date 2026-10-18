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

    /**
     * Test 8: Mismatched legacyShiftId (cross-branch or cross-company) is rejected, returns null,
     * and strictly does NOT leak foreign shift report revision, allocation version, or liability.
     */
    public function test_mismatched_legacy_shift_id_returns_null_and_does_not_leak_foreign_data(): void
    {
        // Setup foreign CashierShift in Branch B
        $templateB = ShiftTemplate::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierB = Cashier::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierShiftB = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashierB->id,
            'shift_id' => $templateB->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'cash_collected' => '40.00',
            'closing_balance' => '40.00',
            'variance' => '-20.00',
            'status' => 'in_progress',
        ]));

        // Record revision 5 for Branch B shift
        $revB = $this->revisionService->recordCashierRevision($cashierShiftB, 'cashier', $cashierB->id);
        DB::table('shift_report_aggregates')->where('source_id', $cashierShiftB->id)->update(['current_revision_number' => 5]);

        // Setup liability allocation version 3 for Branch B shift
        ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $cashierShiftB->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'version' => 3,
            'report_revision' => '5',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $cashierB->id,
            'reason' => 'Branch B shortage',
            'manager_approval_status' => 'approved',
        ]);

        // Operation in Branch A with mismatched legacyShiftId pointing to Branch B shift
        $op = $this->createShiftOperation([
            'legacyShiftId' => $cashierShiftB->id,
            'pkg6Review' => null,
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        // Must strictly NOT leak Branch B revision (5) or allocation version (3) or liability
        $this->assertNull($response->json('reportRevision'), 'Must return null for mismatched legacyShiftId');
        $this->assertNull($response->json('allocationVersion'), 'Must return null for mismatched legacyShiftId');
        $this->assertNull($response->json('liability'), 'Must return null for mismatched legacyShiftId');

        $this->assertNull($response->json('payload.reportRevision'));
        $this->assertNull($response->json('payload.allocationVersion'));
        $this->assertNull($response->json('payload.liability'));
    }

    /**
     * Test 9: pkg6Review.revision_id pointing to a revision of another shift is rejected and returns null.
     */
    public function test_pkg6_review_revision_id_pointing_to_foreign_shift_revision_returns_null(): void
    {
        // Setup foreign CashierShift in Branch B with a revision
        $templateB = ShiftTemplate::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierB = Cashier::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierShiftB = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashierB->id,
            'shift_id' => $templateB->id,
            'shift_date' => today(),
            'status' => 'in_progress',
        ]));
        $revB = $this->revisionService->recordCashierRevision($cashierShiftB, 'cashier', $cashierB->id);
        DB::table('shift_report_aggregates')->where('source_id', $cashierShiftB->id)->update(['current_revision_number' => 7]);

        // Shift A has NO aggregate and NO revisions of its own
        $op = $this->createShiftOperation([
            'pkg6Review' => [
                'revision_id' => $revB->id, // Foreign revision from Branch B
            ],
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertNull($response->json('reportRevision'), 'Must NOT adopt revision_number from an unrelated shift revision');
        $this->assertNull($response->json('payload.reportRevision'));
    }

    /**
     * Test 10: Accountant correction accepts all 4 ID aliases:
     * CashierShift UUID, AdminShift UUID, Operation UUID, and Operation public ID (SHF-XXXXXX).
     */
    public function test_accountant_correction_supports_all_id_aliases(): void
    {
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

        $lock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'branch_manager_shift_id' => $this->workdayA->id,
            'report_revision' => (string) $rev1->id,
            'allocation_version' => 1,
            'locked_by_id' => $this->managerA->id,
            'locked_at' => now(),
        ]);

        $op = $this->createShiftOperation();

        // --- Alias 1: CashierShift UUID ---
        $res1 = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", [
                'expected_revision' => 1,
                'expected_allocation_version' => 1,
                'reason' => 'Correction via CashierShift UUID',
                'changes' => ['card_payments' => '30.00'],
                'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 3000]],
            ])
            ->assertOk();
        $this->assertSame(2, $res1->json('revision_number'));
        $this->assertSame(2, $res1->json('allocation_version'));

        // Update daily lock for revision 2, allocation 2
        $lock->update([
            'report_revision' => (string) $res1->json('revision_id'),
            'allocation_version' => 2,
        ]);

        // --- Alias 2: AdminShift UUID ---
        $res2 = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->adminShiftA->id}/corrections", [
                'expected_revision' => 2,
                'expected_allocation_version' => 2,
                'reason' => 'Correction via AdminShift UUID',
                'changes' => ['card_payments' => '25.00'],
                'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 3500]],
            ])
            ->assertOk();
        $this->assertSame(3, $res2->json('revision_number'));
        $this->assertSame(3, $res2->json('allocation_version'));

        // Update daily lock for revision 3, allocation 3
        $lock->update([
            'report_revision' => (string) $res2->json('revision_id'),
            'allocation_version' => 3,
        ]);

        // --- Alias 3: Operation UUID ---
        $res3 = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$op->id}/corrections", [
                'expected_revision' => 3,
                'expected_allocation_version' => 3,
                'reason' => 'Correction via Operation UUID',
                'changes' => ['card_payments' => '20.00'],
                'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 4000]],
            ])
            ->assertOk();
        $this->assertSame(4, $res3->json('revision_number'));
        $this->assertSame(4, $res3->json('allocation_version'));

        // Update daily lock for revision 4, allocation 4
        $lock->update([
            'report_revision' => (string) $res3->json('revision_id'),
            'allocation_version' => 4,
        ]);

        // --- Alias 4: Operation public ID (SHF-XXXXXX) ---
        $res4 = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$op->public_id}/corrections", [
                'expected_revision' => 4,
                'expected_allocation_version' => 4,
                'reason' => 'Correction via Operation public_id',
                'changes' => ['card_payments' => '15.00'],
                'shortage_allocations' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 4500]],
            ])
            ->assertOk();
        $this->assertSame(5, $res4->json('revision_number'));
        $this->assertSame(5, $res4->json('allocation_version'));

        // Verify read endpoint reflects latest versions
        $read = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();
        $this->assertSame(5, $read->json('reportRevision'));
        $this->assertSame(5, $read->json('allocationVersion'));
        $this->assertSame(5, $read->json('liability.version'));
    }

    /**
     * Test 11: Unauthorized accountant (Accountant B) cannot correct Branch A shift via any alias (403),
     * and strictly causes ZERO mutations or financial postings.
     */
    public function test_unauthorized_accountant_rejected_on_all_aliases_with_zero_mutation(): void
    {
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

        $op = $this->createShiftOperation();

        // Baseline counts before unauthorized attempts
        $emCountBefore = EmployeeMovement::count();
        $pltCountBefore = PersonalLedgerTransaction::count();
        $cctCountBefore = CashierCustodyTransaction::count();
        $postingsBefore = DB::table('shift_liability_postings')->count();
        $revCountBefore = ShiftReportRevision::count();
        $allocCountBefore = ShiftLiabilityAllocation::count();

        $payload = [
            'expected_revision' => 1,
            'expected_allocation_version' => 1,
            'reason' => 'Unauthorized breach attempt',
            'changes' => ['card_payments' => '30.00'],
        ];

        // 1. CashierShift UUID -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantB, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->cashierShiftA->id}/corrections", $payload)
            ->assertStatus(403);

        // 2. AdminShift UUID -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantB, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$this->adminShiftA->id}/corrections", $payload)
            ->assertStatus(403);

        // 3. Operation UUID -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantB, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$op->id}/corrections", $payload)
            ->assertStatus(403);

        // 4. Operation public ID -> 403
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantB, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$op->public_id}/corrections", $payload)
            ->assertStatus(403);

        // Strictly zero side-effects
        $this->assertSame($emCountBefore, EmployeeMovement::count());
        $this->assertSame($pltCountBefore, PersonalLedgerTransaction::count());
        $this->assertSame($cctCountBefore, CashierCustodyTransaction::count());
        $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count());
        $this->assertSame($revCountBefore, ShiftReportRevision::count());
        $this->assertSame($allocCountBefore, ShiftLiabilityAllocation::count());
    }

    /**
     * Test 12: Operation pointing to a shift mismatched with company/branch scope is rejected (404),
     * preventing cross-scope shift mutation even by authorized users.
     */
    public function test_mismatched_operation_shift_scope_rejected_without_mutation(): void
    {
        // Foreign CashierShift in Branch B
        $templateB = ShiftTemplate::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierB = Cashier::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierShiftB = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashierB->id,
            'shift_id' => $templateB->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'cash_collected' => '40.00',
            'closing_balance' => '40.00',
            'variance' => '0.00',
            'status' => 'in_progress',
        ]));

        // Operation in Branch A, but legacyShiftId maliciously set to Branch B shift
        $mismatchedOp = $this->createShiftOperation([
            'legacyShiftId' => $cashierShiftB->id,
        ]);

        $payload = [
            'expected_revision' => 1,
            'reason' => 'Attempt to correct mismatched shift',
            'changes' => ['card_payments' => '30.00'],
        ];

        // 1. Accountant A attempts correction via mismatched operation -> 404 (does not resolve to valid Branch A shift)
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$mismatchedOp->id}/corrections", $payload)
            ->assertStatus(404);

        // 2. Head accountant attempts correction via mismatched operation -> 404
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$mismatchedOp->id}/corrections", $payload)
            ->assertStatus(404);

        // Zero changes to Branch B shift
        $this->assertSame('40.00', (string) $cashierShiftB->fresh()->card_payments);
        $this->assertSame(0, EmployeeMovement::count());
        $this->assertSame(0, PersonalLedgerTransaction::count());
    }

    /**
     * Finding F-BD3-01: Mismatched bridge mapping in asab_shifts pointing to a foreign CashierShift
     * (cross-branch or cross-company) must NOT override canonical cashier_shifts -> shifts -> branches
     * scope mismatch. The fallback must fail-closed and return null.
     */
    public function test_f_bd3_01_mismatched_bridge_mapping_cross_branch_and_cross_company(): void
    {
        // -------------------------------------------------------------
        // Case 1: Cross-Branch Mismatch via asab_shifts bridge
        // -------------------------------------------------------------
        $templateB = ShiftTemplate::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierB = Cashier::factory()->create(['branch_id' => $this->branchB->id]);
        $cashierShiftB = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashierB->id,
            'shift_id' => $templateB->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'cash_collected' => '40.00',
            'closing_balance' => '40.00',
            'variance' => '-20.00',
            'status' => 'in_progress',
        ]));

        // Record revision 5 for Branch B shift
        $revB = $this->revisionService->recordCashierRevision($cashierShiftB, 'cashier', $cashierB->id);
        DB::table('shift_report_aggregates')->where('source_id', $cashierShiftB->id)->update(['current_revision_number' => 5]);

        // Branch A AdminShift bridge mistakenly pointing legacy_shift_id to Branch B's cashier shift
        $bridgeShiftBranchA = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $cashierShiftB->id,
            'name' => 'Bridge Shift in Branch A pointing to Branch B',
            'role' => 'cashier',
            'shift_date' => today(),
            'status' => 'pending_review',
        ]);

        // Operation in Branch A referencing this bridge shift
        $opBranchA = Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $bridgeShiftBranchA->id,
            'amount' => 10000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'shiftId' => $bridgeShiftBranchA->id,
                'legacyShiftId' => null,
            ],
        ]);

        // Baseline financial counts before read operations
        $emBefore = EmployeeMovement::count();
        $pltBefore = PersonalLedgerTransaction::count();
        $cctBefore = CashierCustodyTransaction::count();
        $postingsBefore = DB::table('shift_liability_postings')->count();
        $revBefore = ShiftReportRevision::count();
        $allocBefore = ShiftLiabilityAllocation::count();
        $shareBefore = ShiftLiabilityShare::count();

        $resBranch = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$opBranchA->id}")
            ->assertOk();

        // Must FAIL CLOSED: cannot trust bridge mapping when canonical shift is in Branch B
        $this->assertNull(
            $resBranch->json('reportRevision'),
            'F-BD3-01: Must NOT leak foreign Branch B revision via mismatched asab_shifts bridge'
        );
        $this->assertNull(
            $resBranch->json('allocationVersion'),
            'F-BD3-01: Must NOT leak foreign Branch B allocation via mismatched asab_shifts bridge'
        );
        $this->assertNull(
            $resBranch->json('liability'),
            'F-BD3-01: Must NOT leak foreign Branch B liability via mismatched asab_shifts bridge'
        );

        // -------------------------------------------------------------
        // Case 2: Cross-Company / Cross-Tenant Mismatch via bridge
        // -------------------------------------------------------------
        $company2 = AsabCompany::create([
            'name' => 'Foreign Company 2',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);
        $brand2 = AsabBrand::create([
            'company_id' => $company2->id,
            'name' => 'Brand 2',
            'status' => 'active',
            'sub_status' => 'active',
        ]);
        $branch2 = Branch::factory()->create([
            'asab_company_id' => $company2->id,
            'asab_brand_id' => $brand2->id,
            'name' => 'Foreign Branch 2',
        ]);
        $template2 = ShiftTemplate::factory()->create(['branch_id' => $branch2->id]);
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch2->id]);
        $cashierShiftCompany2 = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $template2->id,
            'shift_date' => today(),
            'total_sales' => '200.00',
            'status' => 'in_progress',
        ]));

        // Record revision 9 for Company 2 shift
        $rev2 = $this->revisionService->recordCashierRevision($cashierShiftCompany2, 'cashier', $cashier2->id);
        DB::table('shift_report_aggregates')->where('source_id', $cashierShiftCompany2->id)->update(['current_revision_number' => 9]);

        // Company 1 AdminShift bridge pointing legacy_shift_id to Company 2 cashier shift
        $bridgeShiftCompany1 = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $cashierShiftCompany2->id,
            'name' => 'Bridge Shift in Company 1 pointing to Company 2',
            'role' => 'cashier',
            'shift_date' => today(),
            'status' => 'pending_review',
        ]);

        // Operation in Company 1 referencing this cross-company bridge
        $opCompany1 = Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $bridgeShiftCompany1->id,
            'amount' => 20000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'shiftId' => $bridgeShiftCompany1->id,
                'legacyShiftId' => null,
            ],
        ]);

        $resCompany = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$opCompany1->id}")
            ->assertOk();

        // Must FAIL CLOSED: cannot trust bridge mapping across companies
        $this->assertNull(
            $resCompany->json('reportRevision'),
            'F-BD3-01: Must NOT leak foreign Company 2 revision via cross-company bridge'
        );
        $this->assertNull($resCompany->json('allocationVersion'));
        $this->assertNull($resCompany->json('liability'));

        // Verify zero mutations across all financial tables during rejected mismatched queries
        $this->assertSame($emBefore, EmployeeMovement::count(), 'Zero EmployeeMovement mutations on mismatched scope query');
        $this->assertSame($pltBefore, PersonalLedgerTransaction::count(), 'Zero PersonalLedgerTransaction mutations on mismatched scope query');
        $this->assertSame($cctBefore, CashierCustodyTransaction::count(), 'Zero CashierCustodyTransaction mutations on mismatched scope query');
        $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count(), 'Zero shift_liability_postings on mismatched scope query');
        $this->assertSame($revBefore + 1, ShiftReportRevision::count(), 'No extra ShiftReportRevision created by query');
        $this->assertSame($allocBefore, ShiftLiabilityAllocation::count(), 'Zero ShiftLiabilityAllocation mutations on mismatched scope query');
        $this->assertSame($shareBefore, ShiftLiabilityShare::count(), 'Zero ShiftLiabilityShare mutations on mismatched scope query');

        // -------------------------------------------------------------
        // Case 3: Positive Control — Legitimate matching bridge succeeds
        // -------------------------------------------------------------
        $legitBridge = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $this->cashierShiftA->id,
            'name' => 'Legitimate Matching Bridge in Branch A',
            'role' => 'cashier',
            'shift_date' => today(),
            'status' => 'pending_review',
        ]);

        $revA = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);
        DB::table('shift_report_aggregates')->where('source_id', $this->cashierShiftA->id)->update(['current_revision_number' => 2]);

        $opLegit = Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $legitBridge->id,
            'amount' => 10000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'shiftId' => $legitBridge->id,
                'legacyShiftId' => null,
            ],
        ]);

        $resLegit = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$opLegit->id}")
            ->assertOk();

        $this->assertSame(2, $resLegit->json('reportRevision'), 'Legitimate matching bridge must resolve reportRevision');
    }

    /**
     * FIN-01: Operation GET projects confirmedOpeningHalalas from confirmed receipt evidence (10.00 SAR -> 1000 halalas),
     * never derives from configured float (50000 halalas), and preserves correct unchanged expected cash.
     */
    public function test_shift_operation_read_projects_confirmed_opening_from_receipt_evidence_and_preserves_expected_cash(): void
    {
        $op = $this->createShiftOperation();

        // 1. Revision with confirmed cash count
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);

        // 2. Confirmed handover receipt of 10.00 SAR (1,000 halalas) into this cashier shift
        DB::table('cashier_shift_handover_receipts')->insert([
            'id' => (string) Str::uuid(),
            'cashier_shift_handover_id' => null,
            'branch_manager_cash_transfer_id' => null,
            'receiving_cashier_shift_id' => $this->cashierShiftA->id,
            'receiving_cashier_id' => $this->cashierA->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '10.00',
            'confirmed_by_id' => $this->cashierA->id,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cash count with 10.00 SAR confirmed opening
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShiftA->id,
            'gross_halalas' => 11500,
            'cards_halalas' => 5000,
            'apps_halalas' => 2500,
            'confirmed_opening_halalas' => 1000,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 3000,
            'expected_halalas' => 5000, // 11500 - 5000 - 2500 + 1000 = 5000
            'variance_halalas' => -2000,
        ]);

        // Update operation payload: configured float is 50000 (500.00 SAR), expected cash is 5000 (50.00 SAR)
        $op->update([
            'payload' => array_merge($op->payload ?? [], [
                'salesHalalas' => 11500,
                'cardTotalHalalas' => 5000,
                'aggregatorTotalsHalalas' => 2500,
                'openingFloatHalalas' => 50000, // configured float
                'cashExpectedHalalas' => 5000,  // expected cash: 115 - 50 - 25 + 10 = 50 SAR
                'cashActualHalalas' => 3000,
                'varianceHalalas' => -2000,
            ]),
        ]);

        $res = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        // FIN-01 assertions:
        // Confirmed opening is projected as 1,000 halalas (from confirmed receipt 10.00 SAR)
        $this->assertSame(1000, $res->json('confirmedOpeningHalalas'));
        $this->assertSame(1000, $res->json('payload.confirmedOpeningHalalas'));
        $this->assertSame(1000, $res->json('payload.confirmed_opening_halalas'));

        // Configured float remains untouched and separate
        $this->assertSame(50000, $res->json('payload.openingFloatHalalas'));

        // Expected cash remains 5,000 halalas (unchanged financial calculation)
        $this->assertSame(5000, $res->json('payload.cashExpectedHalalas'));
    }

    /**
     * COR-05-01: Operation GET projects cash_collected and handover_notes,
     * distinguishing authoritative null notes from unsupported fields.
     */
    public function test_shift_operation_read_projects_cash_collected_and_authoritative_null_notes(): void
    {
        $op = $this->createShiftOperation();

        // Case A: Cashier shift with cash_collected and authoritative null notes
        $this->cashierShiftA->update([
            'cash_collected' => '60.00',
            'handover_notes' => null,
        ]);

        $resA = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame(6000, $resA->json('cashCollectedHalalas'));
        $this->assertSame(6000, $resA->json('payload.cashCollectedHalalas'));
        $this->assertSame('60.00', $resA->json('payload.cash_collected'));
        $this->assertTrue(array_key_exists('handoverNotes', $resA->json()));
        $this->assertNull($resA->json('handoverNotes'), 'Authoritative null note must be projected as null');
        $this->assertTrue(array_key_exists('handoverNotes', $resA->json('payload')));
        $this->assertNull($resA->json('payload.handoverNotes'));

        // Case B: Cashier shift with non-null handover notes
        $this->cashierShiftA->update([
            'handover_notes' => 'Old handover notes from cashier',
        ]);

        $resB = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame('Old handover notes from cashier', $resB->json('handoverNotes'));
        $this->assertSame('Old handover notes from cashier', $resB->json('payload.handoverNotes'));
        $this->assertSame('Old handover notes from cashier', $resB->json('payload.handover_notes'));
    }

    /**
     * COR-05-02: Operation GET projects lastCorrection evidence for current revision
     * to safely reconcile lost responses without duplicate financial POSTs.
     */
    public function test_shift_operation_read_projects_last_correction_for_lost_post_response_attribution(): void
    {
        $op = $this->createShiftOperation();

        // 1. Revision 2 setup
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);
        $rev2 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'accountant', $this->accountantA->id);
        DB::table('shift_report_aggregates')->where('source_id', $this->cashierShiftA->id)->update(['current_revision_number' => 2]);

        // 2. Record accountant correction command in shift_report_corrections
        DB::table('shift_report_corrections')->insert([
            'id' => (string) Str::uuid(),
            'report_aggregate_id' => $rev2->report_aggregate_id,
            'previous_revision_id' => $rev1->id,
            'new_revision_id' => $rev2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary_halalas',
            'old_value' => '40.00',
            'new_value' => '30.00',
            'old_halalas' => 4000,
            'new_halalas' => 3000,
            'reason' => 'Audit adjustment on card receipts',
            'actor_type' => 'accountant',
            'actor_id' => $this->accountantA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'operation_id' => $op->id,
            'created_at' => now(),
        ]);

        $res = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame(2, $res->json('reportRevision'));
        $this->assertNotNull($res->json('lastCorrection'));
        $this->assertSame(2, $res->json('lastCorrection.revisionNumber'));
        $this->assertSame((string) $op->id, $res->json('lastCorrection.commandId'));
        $this->assertSame('Audit adjustment on card receipts', $res->json('lastCorrection.reason'));
        $this->assertSame((string) $this->accountantA->id, $res->json('lastCorrection.actorId'));
        $this->assertSame('accountant', $res->json('lastCorrection.actorType'));
        $this->assertSame($res->json('lastCorrection'), $res->json('payload.lastCorrection'));
    }

    /**
     * COR-05-02 Regression:
     * Two shifts in the same branch with the same report revision (revision 2).
     * Another shift's correction MUST NOT appear as the target shift's lastCorrection.
     */
    public function test_cor_05_02_regression_two_shifts_same_branch_same_revision_strictly_isolated(): void
    {
        $opA = $this->createShiftOperation();

        // Setup Shift B in the same branch
        $template = ShiftTemplate::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Evening Shift Template',
            'start_time' => '18:00:00',
            'end_time' => '02:00:00',
        ]);
        $cashierB = Cashier::factory()->create(['branch_id' => $this->branchA->id]);
        $cashierShiftB = CashierShift::factory()->create([
            'shift_id' => $template->id,
            'cashier_id' => $cashierB->id,
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
            'variance' => '0.00',
            'status' => 'in_progress',
        ]);
        $adminShiftB = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'user_id' => $this->accountantA->id,
            'legacy_shift_id' => $cashierShiftB->id,
            'name' => 'Shift B Same Branch',
            'start_time' => now()->subHours(4),
            'status' => 'active',
        ]);
        $opB = Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $adminShiftB->id,
            'amount' => 10000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'shiftId' => $adminShiftB->id,
                'legacyShiftId' => $cashierShiftB->id,
            ],
        ]);

        // Both shifts reach revision 2
        $revA1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);
        $revA2 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'accountant', $this->accountantA->id);
        DB::table('shift_report_aggregates')->where('source_id', $this->cashierShiftA->id)->update(['current_revision_number' => 2]);

        $revB1 = $this->revisionService->recordCashierRevision($cashierShiftB, 'cashier', $cashierB->id);
        $revB2 = $this->revisionService->recordCashierRevision($cashierShiftB, 'accountant', $this->accountantA->id);
        DB::table('shift_report_aggregates')->where('source_id', $cashierShiftB->id)->update(['current_revision_number' => 2]);

        $cmdA = (string) Str::uuid();
        $cmdB = (string) Str::uuid();

        // Correction for Shift A
        DB::table('shift_report_corrections')->insert([
            'id' => (string) Str::uuid(),
            'report_aggregate_id' => $revA2->report_aggregate_id,
            'previous_revision_id' => $revA1->id,
            'new_revision_id' => $revA2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary_halalas',
            'old_value' => '40.00',
            'new_value' => '30.00',
            'old_halalas' => 4000,
            'new_halalas' => 3000,
            'reason' => 'Correction for Shift A only',
            'actor_type' => 'accountant',
            'actor_id' => $this->accountantA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'operation_id' => $cmdA,
            'created_at' => now()->subMinute(),
        ]);

        // Correction for Shift B (created later in the same branch at revision 2)
        DB::table('shift_report_corrections')->insert([
            'id' => (string) Str::uuid(),
            'report_aggregate_id' => $revB2->report_aggregate_id,
            'previous_revision_id' => $revB1->id,
            'new_revision_id' => $revB2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary_halalas',
            'old_value' => '40.00',
            'new_value' => '35.00',
            'old_halalas' => 4000,
            'new_halalas' => 3500,
            'reason' => 'Correction for Shift B only',
            'actor_type' => 'accountant',
            'actor_id' => $this->accountantA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'operation_id' => $cmdB,
            'created_at' => now(),
        ]);

        // Query Operation A: MUST project Shift A's correction and NEVER Shift B's
        $resA = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$opA->id}")
            ->assertOk();

        $this->assertSame(2, $resA->json('reportRevision'));
        $this->assertSame(2, $resA->json('lastCorrection.revisionNumber'));
        $this->assertSame($cmdA, $resA->json('lastCorrection.commandId'));
        $this->assertSame('Correction for Shift A only', $resA->json('lastCorrection.reason'));
        $this->assertNotSame($cmdB, $resA->json('lastCorrection.commandId'));

        // Query Operation B: MUST project Shift B's correction and NEVER Shift A's
        $resB = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$opB->id}")
            ->assertOk();

        $this->assertSame(2, $resB->json('reportRevision'));
        $this->assertSame(2, $resB->json('lastCorrection.revisionNumber'));
        $this->assertSame($cmdB, $resB->json('lastCorrection.commandId'));
        $this->assertSame('Correction for Shift B only', $resB->json('lastCorrection.reason'));
        $this->assertNotSame($cmdA, $resB->json('lastCorrection.commandId'));
    }

    /**
     * COR-05-02 Regression:
     * Two corrections by the same accountant using the same reason.
     * Demonstrates that reason and actor ID do NOT prove command success,
     * and only exact commandId (Idempotency-Key) attributes the command.
     */
    public function test_cor_05_02_regression_same_accountant_and_reason_distinguished_by_command_id(): void
    {
        $op = $this->createShiftOperation();

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'cashier', $this->cashierA->id);
        $rev2 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'accountant', $this->accountantA->id);
        $rev3 = $this->revisionService->recordCashierRevision($this->cashierShiftA, 'accountant', $this->accountantA->id);
        DB::table('shift_report_aggregates')->where('source_id', $this->cashierShiftA->id)->update(['current_revision_number' => 3]);

        $cmd1 = (string) Str::uuid();
        $cmd2 = (string) Str::uuid();
        $sharedReason = 'Routine end of day card reconciliation';

        // Rev 2 correction
        DB::table('shift_report_corrections')->insert([
            'id' => (string) Str::uuid(),
            'report_aggregate_id' => $rev2->report_aggregate_id,
            'previous_revision_id' => $rev1->id,
            'new_revision_id' => $rev2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary_halalas',
            'old_value' => '40.00',
            'new_value' => '30.00',
            'old_halalas' => 4000,
            'new_halalas' => 3000,
            'reason' => $sharedReason,
            'actor_type' => 'accountant',
            'actor_id' => $this->accountantA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'operation_id' => $cmd1,
            'created_at' => now()->subMinute(),
        ]);

        // Rev 3 correction (same accountant, same reason text, but new command identity)
        DB::table('shift_report_corrections')->insert([
            'id' => (string) Str::uuid(),
            'report_aggregate_id' => $rev3->report_aggregate_id,
            'previous_revision_id' => $rev2->id,
            'new_revision_id' => $rev3->id,
            'previous_revision_number' => 2,
            'new_revision_number' => 3,
            'field_name' => 'card_payments',
            'field_type' => 'monetary_halalas',
            'old_value' => '30.00',
            'new_value' => '25.00',
            'old_halalas' => 3000,
            'new_halalas' => 2500,
            'reason' => $sharedReason,
            'actor_type' => 'accountant',
            'actor_id' => $this->accountantA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'operation_id' => $cmd2,
            'created_at' => now(),
        ]);

        $res = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        // Latest revision is 3: lastCorrection MUST match $cmd2, not $cmd1
        $this->assertSame(3, $res->json('reportRevision'));
        $this->assertSame(3, $res->json('lastCorrection.revisionNumber'));
        $this->assertSame($cmd2, $res->json('lastCorrection.commandId'));
        $this->assertNotSame($cmd1, $res->json('lastCorrection.commandId'));
    }

    /**
     * COR-05-02 Regression:
     * Lost response after successful financial POST vs when POST did not commit.
     * Demonstrates idempotency, zero duplicate side effects, and strict tenant/branch isolation.
     */
    public function test_cor_05_02_regression_lost_response_reconciliation_and_zero_duplicate_effects(): void
    {
        $testTemplate = ShiftTemplate::factory()->create(['branch_id' => $this->branchA->id]);
        $testCashier = Cashier::factory()->create(['branch_id' => $this->branchA->id]);
        $testShift = CashierShift::factory()->create([
            'shift_id' => $testTemplate->id,
            'cashier_id' => $testCashier->id,
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
            'variance' => '0.00',
            'status' => 'in_progress',
        ]);
        $testAdminShift = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $testShift->id,
            'name' => 'Test Isolated Shift',
            'status' => 'active',
        ]);
        $op = Operation::create([
            'public_id' => 'SHF-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'shifts',
            'origin' => 'mobile',
            'source_module' => 'shifts',
            'source_id' => $testAdminShift->id,
            'amount' => 10000,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'shiftId' => $testAdminShift->id,
                'legacyShiftId' => $testShift->id,
            ],
        ]);

        // 1. Initial CashCount at Rev 1
        $rev1 = $this->revisionService->recordCashierRevision($testShift, 'cashier', $testCashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $testShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000,
            'expected_halalas' => 6000,
            'variance_halalas' => 0,
        ]);

        $clientCommandId = (string) Str::uuid();
        $payload = [
            'expected_revision' => 1,
            'reason' => 'Reconciling lost response test',
            'changes' => [
                'card_payments' => '30.00',
            ],
            'shortage_allocations' => [
                [
                    'type' => 'cashier',
                    'id' => $testCashier->id,
                    'amount' => 1000,
                ],
            ],
        ];

        // 2. Client executes POST /accountant/shifts/{id}/corrections with Idempotency-Key
        $postRes = $this->withHeader('Idempotency-Key', $clientCommandId)
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$testShift->id}/corrections", $payload);
        $postRes->assertOk();

        $this->assertSame(2, $postRes->json('revision_number'));

        // 3. Assume response was lost in transit: Client reads Operation GET to reconcile
        $opRes = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        // Exact attribution proven:
        $this->assertSame(2, $opRes->json('reportRevision'));
        $this->assertNotNull($opRes->json('lastCorrection'));
        $this->assertSame(2, $opRes->json('lastCorrection.revisionNumber'));
        $this->assertSame($clientCommandId, $opRes->json('lastCorrection.commandId'));

        // 4. Client replays exact same POST: returns cached snapshot, ZERO duplicate records
        $correctionsCountBefore = DB::table('shift_report_corrections')->count();
        $allocationsCountBefore = DB::table('shift_liability_allocations')->count();

        $replayRes = $this->withHeader('Idempotency-Key', $clientCommandId)
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$testShift->id}/corrections", $payload)
            ->assertOk();

        $this->assertSame($postRes->getContent(), $replayRes->getContent());
        $this->assertSame($correctionsCountBefore, DB::table('shift_report_corrections')->count(), 'Zero duplicate corrections');
        $this->assertSame($allocationsCountBefore, DB::table('shift_liability_allocations')->count(), 'Zero duplicate allocations');

        // 5. Lost response when POST did NOT commit:
        // A stale request with different commandId fails with 409 and never mutates
        $failedCommandId = (string) Str::uuid();
        $stalePayload = array_merge($payload, ['expected_revision' => 1]); // Stale! Current is 2

        $failRes = $this->withHeader('Idempotency-Key', $failedCommandId)
            ->actingAs($this->accountantA, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$testShift->id}/corrections", $stalePayload);

        $failRes->assertStatus(409);

        // Operation GET still reflects only the committed command, not the failed one
        $opResAfterFail = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertSame(2, $opResAfterFail->json('reportRevision'));
        $this->assertSame($clientCommandId, $opResAfterFail->json('lastCorrection.commandId'));
        $this->assertNotSame($failedCommandId, $opResAfterFail->json('lastCorrection.commandId'));

        // 6. Strict tenant and branch isolation:
        // Accountant B in foreign branch cannot view Operation A (returns 404 NotFound)
        $this->actingAs($this->accountantB, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertNotFound();
    }
}
