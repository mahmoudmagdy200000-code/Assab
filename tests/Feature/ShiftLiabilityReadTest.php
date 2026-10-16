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
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift as ShiftTemplate;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityShare;
use Tests\TestCase;

/**
 * BD-2 / SHF Allocation and Manager Approval Read API.
 *
 * Exposes read-only shortage liability allocation and manager approval status
 * in GET operations/{id} for shift operations, strictly enforcing role/branch
 * isolation, preserving archived employee history (MEM-06), and guaranteeing
 * zero financial movements or ledger mutations.
 */
class ShiftLiabilityReadTest extends TestCase
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
    protected CashierShift $cashierShiftA;
    protected AdminShift $adminShiftA;
    protected Employee $activeEmployeeA;
    protected Employee $archivedEmployeeA;
    protected Employee $foreignEmployeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Liability Test Company',
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

        // Head accountant: company-wide scope
        $this->head = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Head User',
            'email' => 'head@liability.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->head->id,
            'role_key' => 'head',
            'scope' => 'all',
        ]);

        // Accountant scoped to Branch A
        $this->accountantA = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant A',
            'email' => 'accountantA@liability.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountantA->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branchA->id],
        ]);

        // Accountant scoped to Branch B
        $this->accountantB = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant B',
            'email' => 'accountantB@liability.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountantB->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branchB->id],
        ]);

        // Cashier in Branch A
        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Ahmed Cashier',
        ]);

        // Branch manager created with branch factory
        $this->managerA = BranchManager::where('branch_id', $this->branchA->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerA->update(['name' => 'Mahmoud Manager']);

        $templateA = ShiftTemplate::factory()->create(['branch_id' => $this->branchA->id]);

        $this->cashierShiftA = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $templateA->id,
            'shift_date' => today(),
            'total_sales' => '115.00',
            'card_payments' => '50.00',
            'cash_collected' => '30.00',
            'variance' => '-20.00',
        ]));

        $this->adminShiftA = AdminShift::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'legacy_shift_id' => $this->cashierShiftA->id,
            'name' => 'Morning Shift A',
            'role' => 'cashier',
            'shift_date' => today(),
            'status' => 'pending_review',
            'sales_amount' => 11500,
            'cash_actual' => 3000,
            'cash_expected' => 5000,
            'variance' => -2000,
            'cash_count_state' => 'counted',
        ]);

        // Active employee in Branch A
        $this->activeEmployeeA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'name' => 'Youssef Barista',
            'emp_number' => 'EMP-001',
            'role' => 'باريستا',
            'status' => 'active',
        ]);

        // Archived / terminated employee in Branch A (MEM-06)
        $this->archivedEmployeeA = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'name' => 'Omar Archived',
            'emp_number' => 'EMP-002',
            'role' => 'كاشير سابق',
            'status' => 'terminated',
        ]);
        $this->archivedEmployeeA->delete(); // Soft-deleted

        // Foreign employee in Branch B
        $this->foreignEmployeeB = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchB->id,
            'name' => 'Foreign Employee B',
            'emp_number' => 'EMP-B01',
            'role' => 'عامل فرع ب',
            'status' => 'active',
        ]);
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
            'amount' => 11500,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => array_merge([
                'shiftId' => $this->adminShiftA->id,
                'legacyShiftId' => $this->cashierShiftA->id,
                'salesHalalas' => 11500,
                'cardTotalHalalas' => 5000,
                'aggregatorTotalsHalalas' => 2500,
                'openingFloatHalalas' => 1000,
                'cashExpectedHalalas' => 5000,
                'cashActualHalalas' => 3000,
                'varianceHalalas' => -2000,
                'cashCountState' => 'counted',
                'pkg6Review' => [
                    'phase' => 'accountant_review',
                    'status' => 'pending',
                ],
            ], $payloadOverride),
        ]);
    }

    public function test_shift_operation_without_allocation_returns_null_liability(): void
    {
        $op = $this->createShiftOperation();

        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $this->assertNull($response->json('liability'));
        $this->assertNull($response->json('payload.liability'));
        $this->assertSame('shifts', $response->json('moduleKey'));
    }

    public function test_shift_operation_with_pending_manager_approval_liability(): void
    {
        $op = $this->createShiftOperation();

        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => 'REV-001',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'reason' => 'عجز نقدية في درج الكاشير',
            'manager_approval_status' => 'pending',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 1200,
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'branch_manager',
            'responsible_id' => $this->managerA->id,
            'amount_halalas' => 800,
        ]);

        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $liability = $response->json('liability');
        $this->assertNotNull($liability);
        $this->assertSame(1, $liability['version']);
        $this->assertSame('pending', $liability['managerApprovalStatus']);
        $this->assertSame('cashier', $liability['createdByType']);
        $this->assertCount(2, $liability['shares']);

        $this->assertSame('cashier', $liability['shares'][0]['type']);
        $this->assertSame($this->cashierA->id, $liability['shares'][0]['id']);
        $this->assertSame('Ahmed Cashier', $liability['shares'][0]['name']);
        $this->assertSame(1200, $liability['shares'][0]['amountHalalas']);

        $this->assertSame('branch_manager', $liability['shares'][1]['type']);
        $this->assertSame($this->managerA->id, $liability['shares'][1]['id']);
        $this->assertSame('Mahmoud Manager', $liability['shares'][1]['name']);
        $this->assertSame(800, $liability['shares'][1]['amountHalalas']);

        // Check mirror in payload
        $this->assertSame($liability, $response->json('payload.liability'));

        // Ensure operation status and phase remain separate
        $this->assertSame('pending', $response->json('status'));
        $this->assertSame('accountant_review', $response->json('reportReview.phase'));
    }

    public function test_shift_operation_with_approved_manager_liability(): void
    {
        $op = $this->createShiftOperation();

        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 2,
            'report_revision' => 'REV-002',
            'variance_halalas' => -2000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->managerA->id,
            'reason' => 'اعتماد توزيع العجز بعد مراجعة الكاميرات',
            'manager_approval_status' => 'approved',
            'manager_approved_by' => $this->managerA->id,
            'manager_approved_at' => now(),
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $liability = $response->json('liability');
        $this->assertNotNull($liability);
        $this->assertSame(2, $liability['version']);
        $this->assertSame('approved', $liability['managerApprovalStatus']);
        $this->assertSame('branch_manager', $liability['createdByType']);
        $this->assertCount(1, $liability['shares']);
        $this->assertSame(2000, $liability['shares'][0]['amountHalalas']);
        $this->assertSame('Ahmed Cashier', $liability['shares'][0]['name']);
    }

    public function test_shift_operation_resolves_archived_and_terminated_employee_names_per_mem06(): void
    {
        $op = $this->createShiftOperation();

        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => 'REV-MEM06',
            'variance_halalas' => -2000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->managerA->id,
            'manager_approval_status' => 'approved',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->archivedEmployeeA->id,
            'amount_halalas' => 2000,
        ]);

        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $share = $response->json('liability.shares.0');
        $this->assertSame('employee', $share['type']);
        $this->assertSame($this->archivedEmployeeA->id, $share['id']);
        $this->assertSame('Omar Archived', $share['name']);
        $this->assertSame(2000, $share['amountHalalas']);

        // Verify that the employee is still archived (soft-deleted) and not reactivated
        $this->assertTrue(Employee::onlyTrashed()->whereKey($this->archivedEmployeeA->id)->exists());
        $this->assertSame('terminated', $this->archivedEmployeeA->fresh()->status);
    }

    public function test_branch_isolation_blocks_unauthorized_accountant_and_hides_foreign_branch_names(): void
    {
        $op = $this->createShiftOperation();

        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => 'REV-ISO',
            'variance_halalas' => -2000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'manager_approval_status' => 'pending',
        ]);

        // Malicious/corrupted share referencing an employee from Branch B
        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'employee',
            'responsible_id' => $this->foreignEmployeeB->id,
            'amount_halalas' => 2000,
        ]);

        // 1. Accountant B (assigned to Branch B) attempts to view Branch A's operation -> 404 NOT_FOUND
        $this->actingAs($this->accountantB, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertStatus(404);

        // 2. Head views the operation -> Share referencing Branch B's employee yields name = null (no data leak)
        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $share = $response->json('liability.shares.0');
        $this->assertSame($this->foreignEmployeeB->id, $share['id']);
        $this->assertNull($share['name'], 'Foreign branch employee name must never be leaked across branch boundaries');
    }

    public function test_superseded_allocation_is_ignored_for_active_allocation(): void
    {
        $op = $this->createShiftOperation();

        // Older superseded allocation v1
        $v1 = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => 'REV-V1',
            'variance_halalas' => -1000,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
            'manager_approval_status' => 'pending',
            'superseded_at' => now(),
        ]);
        ShiftLiabilityShare::create([
            'allocation_id' => $v1->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 1000,
        ]);

        // Active allocation v2
        $v2 = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 2,
            'report_revision' => 'REV-V2',
            'variance_halalas' => -2000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->managerA->id,
            'manager_approval_status' => 'approved',
            'superseded_at' => null,
        ]);
        ShiftLiabilityShare::create([
            'allocation_id' => $v2->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        $response = $this->actingAs($this->head, 'sanctum')
            ->getJson("/api/v1/operations/{$op->id}")
            ->assertOk();

        $liability = $response->json('liability');
        $this->assertSame(2, $liability['version']);
        $this->assertSame('approved', $liability['managerApprovalStatus']);
        $this->assertSame('branch_manager', $liability['createdByType']);
        $this->assertSame(2000, $liability['shares'][0]['amountHalalas']);
    }

    public function test_get_is_strictly_idempotent_with_zero_movements_or_ledger_postings(): void
    {
        $op = $this->createShiftOperation();

        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShiftA->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'version' => 1,
            'report_revision' => 'REV-IDEM',
            'variance_halalas' => -2000,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->managerA->id,
            'manager_approval_status' => 'approved',
        ]);

        ShiftLiabilityShare::create([
            'allocation_id' => $allocation->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 2000,
        ]);

        // Baseline counts
        $emCountBefore = EmployeeMovement::count();
        $pltCountBefore = PersonalLedgerTransaction::count();
        $cctCountBefore = CashierCustodyTransaction::count();
        $postingsBefore = DB::table('shift_liability_postings')->count();
        $allocCountBefore = ShiftLiabilityAllocation::count();
        $shareCountBefore = ShiftLiabilityShare::count();
        $opCountBefore = Operation::count();

        // 3 consecutive GET requests
        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($this->head, 'sanctum')
                ->getJson("/api/v1/operations/{$op->id}")
                ->assertOk();

            $this->assertSame(1, $response->json('liability.version'));
            $this->assertSame('approved', $response->json('liability.managerApprovalStatus'));
        }

        // Verify zero mutations
        $this->assertSame($emCountBefore, EmployeeMovement::count(), 'Zero EmployeeMovement records must be created');
        $this->assertSame($pltCountBefore, PersonalLedgerTransaction::count(), 'Zero PersonalLedgerTransaction records must be created');
        $this->assertSame($cctCountBefore, CashierCustodyTransaction::count(), 'Zero CashierCustodyTransaction records must be created');
        $this->assertSame($postingsBefore, DB::table('shift_liability_postings')->count(), 'Zero shift liability postings must be created');
        $this->assertSame($allocCountBefore, ShiftLiabilityAllocation::count(), 'Allocation count unchanged');
        $this->assertSame($shareCountBefore, ShiftLiabilityShare::count(), 'Share count unchanged');
        $this->assertSame($opCountBefore, Operation::count(), 'Operation count unchanged');
    }
}
