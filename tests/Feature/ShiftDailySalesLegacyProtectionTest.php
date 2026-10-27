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

    // Generic portable rebinding: only needed if vendor is junctioned or symlinked from another directory
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

use App\Support\ShiftFinancialCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * BD-4 / Financial Safety: Legacy PKG-6 Daily Sales Protection.
 *
 * Prevents unauthorized modifications (PATCH sales-details) and variance
 * assignments (POST sales-variance/assign) against PKG-6 daily report operations.
 * Ensures zero unauthorized EmployeeMovement rows and zero Ledger transactions,
 * while ensuring non-PKG-6 legacy operations continue to work normally.
 */
class ShiftDailySalesLegacyProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected AsabCompany $company;
    protected AsabBrand $brand;
    protected Branch $branch;
    protected AsabUser $accountant;
    protected Employee $employee;
    protected BranchManagerShift $day;
    protected CashierShift $cashierShift;
    protected Operation $pkg6Operation;
    protected Operation $legacyOperation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'PKG6 Test Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'PKG6 Test Brand',
            'status' => 'active',
            'sub_status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'name' => 'PKG6 Test Branch',
        ]);

        $manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashierShift = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'card_payments' => '20.00',
            'cash_collected' => '60.00',
        ]));

        $this->day = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
            'total_sales' => '100.00',
            'cash_collected' => '60.00',
            'card_payments' => '20.00',
            'aggregator_payments' => '0.00',
            'variance' => '-20.00',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $cashier->id);
        $count = app(ShiftCashCountService::class)->record($this->cashierShift, $revision, 10000, 2000, 0, 6000);

        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->day->id,
            'report_revision' => $count->evidence_revision_id,
            'locked_by_id' => $manager->id,
            'locked_at' => now(),
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant User',
            'email' => 'accountant@pkg6.test',
            'password' => 'secret-test',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cashier Employee',
            'emp_number' => '1001',
            'role' => 'كاشير رئيسي',
            'status' => 'active',
            'is_active' => true,
        ]);

        // Realistic PKG-6 daily report operation produced by BridgeManagerDailyClose
        $this->pkg6Operation = Operation::create([
            'public_id' => 'SAL-P6-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'sales',
            'origin' => 'mobile',
            'source_module' => 'mobile_app',
            'amount' => 10000, // 100.00 SAR in halalas
            'match' => 'diff',
            'diff_note' => 'عجز 20.00 ر.س',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'date' => today()->toDateString(),
                'managerShiftId' => $this->day->id,
                'managerName' => $manager->name,
                'totalHalalas' => 10000,
                'totalSalesHalalas' => 10000,
                'cashHalalas' => 6000,
                'cardHalalas' => 2000,
                'appsHalalas' => 0,
                'varianceHalalas' => -2000,
                'notes' => 'PKG-6 Manager daily close',
                'pkg6Review' => [
                    'phase' => 'accountant_review',
                    'submitted_day_revision_id' => (string) Str::uuid(),
                    'member_source_ids' => [(string) $this->cashierShift->id],
                ],
            ],
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);

        // Standard legacy non-PKG-6 sales operation (e.g. manual / excel / pos)
        $this->legacyOperation = Operation::create([
            'public_id' => 'SAL-LEG-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'sales',
            'origin' => 'excel',
            'source_module' => 'excel_upload',
            'amount' => 50000, // 500.00 SAR
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'date' => today()->toDateString(),
                'totalHalalas' => 50000,
            ],
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);
    }

    protected function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /**
     * Requirement 4: PATCH /sales-details on a PKG-6 daily report must be rejected
     * with 409 PKG6_DAY_REPORT_READ_ONLY before any writes, leaving the report untouched.
     */
    public function test_pkg6_daily_sales_details_patch_is_rejected_with_409_and_leaves_report_untouched(): void
    {
        $payloadBefore = $this->pkg6Operation->fresh()->payload;

        $response = $this->acc()->patchJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 5000, 'posAmountHalalas' => 6000],
                ['key' => 'bank', 'actualAmountHalalas' => 2000, 'posAmountHalalas' => 2000],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonStructure(['error' => ['code', 'message', 'messageAr'], 'requestId'])
            ->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');
        $this->assertNotEmpty($response->json('requestId'));
        $this->assertNotEmpty($response->json('error.messageAr'));

        // Verify report payload is completely untouched
        $payloadAfter = $this->pkg6Operation->fresh()->payload;
        $this->assertSame($payloadBefore, $payloadAfter);
        $this->assertSame(-2000, $payloadAfter['varianceHalalas']);
        $this->assertArrayNotHasKey('reconciliation', $payloadAfter);

        // Verify 0 movements or ledger entries created
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $this->pkg6Operation->id)->count());
        $this->assertSame(0, DB::table('asab_employee_movements')->count());
        $this->assertSame(0, PersonalLedgerTransaction::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
    }

    /**
     * Requirement 4: POST /sales-variance/assign on a PKG-6 daily report must be rejected
     * with 409 PKG6_DAY_REPORT_READ_ONLY before any writes, resulting in 0 EmployeeMovements.
     */
    public function test_pkg6_sales_variance_assign_is_rejected_with_409_and_creates_zero_movements_or_ledger_entries(): void
    {
        $payloadBefore = $this->pkg6Operation->fresh()->payload;

        $response = $this->acc()->postJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-variance/assign", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 2000],
            ],
            'notes' => 'Attempted PKG-6 legacy assignment',
        ]);

        $response->assertStatus(409)
            ->assertJsonStructure(['error' => ['code', 'message', 'messageAr'], 'requestId'])
            ->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');
        $this->assertNotEmpty($response->json('requestId'));
        $this->assertNotEmpty($response->json('error.messageAr'));

        // Zero movements and zero ledger entries
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $this->pkg6Operation->id)->count());
        $this->assertSame(0, DB::table('asab_employee_movements')->count());
        $this->assertSame(0, PersonalLedgerTransaction::count());
        $this->assertSame(0, CashierCustodyTransaction::count());

        // Report payload untouched (no varianceAllocations written)
        $payloadAfter = $this->pkg6Operation->fresh()->payload;
        $this->assertSame($payloadBefore, $payloadAfter);
        $this->assertArrayNotHasKey('varianceAllocations', $payloadAfter);
    }

    /**
     * Test alias route PATCH /reconciliation also enforces 409 rejection.
     */
    public function test_pkg6_reconciliation_alias_route_is_also_rejected_with_409(): void
    {
        $response = $this->acc()->patchJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/reconciliation", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 5000],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');
        $this->assertSame(0, DB::table('asab_employee_movements')->count());
    }

    /**
     * Test alias route POST /variance-allocations also enforces 409 rejection.
     */
    public function test_pkg6_variance_allocations_alias_route_is_also_rejected_with_409(): void
    {
        $response = $this->acc()->postJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/variance-allocations", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 2000],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');
        $this->assertSame(0, DB::table('asab_employee_movements')->count());
        $this->assertSame(0, PersonalLedgerTransaction::count());
    }

    /**
     * Requirement 4: Duplicate requests / double clicks on both routes remain rejected
     * with 409 and still produce 0 unauthorized movements or financial entries.
     */
    public function test_idempotency_duplicate_requests_on_pkg6_endpoints_remain_rejected_with_zero_side_effects(): void
    {
        $initialMovements = DB::table('asab_employee_movements')->count();
        $initialLedger = PersonalLedgerTransaction::count();

        // 1st PATCH
        $res1 = $this->acc()->patchJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 5000]],
        ]);
        $res1->assertStatus(409)->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');

        // 2nd PATCH (duplicate request / double click)
        $res2 = $this->acc()->patchJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-details", [
            'channels' => [['key' => 'cash', 'actualAmountHalalas' => 5000]],
        ]);
        $res2->assertStatus(409)->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');

        // 1st POST
        $res3 = $this->acc()->postJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 2000]],
        ]);
        $res3->assertStatus(409)->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');

        // 2nd POST (duplicate request / double click)
        $res4 = $this->acc()->postJson("/api/v1/company/me/operations/{$this->pkg6Operation->id}/sales-variance/assign", [
            'allocations' => [['empNumber' => '1001', 'amountHalalas' => 2000]],
        ]);
        $res4->assertStatus(409)->assertJsonPath('error.code', 'PKG6_DAY_REPORT_READ_ONLY');

        // Verify side effects strictly remain ZERO across all attempts
        $this->assertSame($initialMovements, DB::table('asab_employee_movements')->count());
        $this->assertSame($initialLedger, PersonalLedgerTransaction::count());
        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $this->pkg6Operation->id)->count());
    }

    /**
     * Requirement 4: Legacy non-PKG-6 operations must continue to allow reconciliation.
     */
    public function test_legacy_non_pkg6_sales_operation_continues_to_allow_reconciliation(): void
    {
        $response = $this->acc()->patchJson("/api/v1/company/me/operations/{$this->legacyOperation->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 30000, 'posAmountHalalas' => 30000],
                ['key' => 'bank', 'actualAmountHalalas' => 20000, 'posAmountHalalas' => 20000],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('totalCollectionHalalas', 50000)
            ->assertJsonPath('varianceHalalas', 0)
            ->assertJsonPath('match', 'exact');

        $this->assertNotNull($this->legacyOperation->fresh()->payload['reconciliation']);
    }

    /**
     * Requirement 4: Legacy non-PKG-6 operations must continue to allow variance assignment.
     */
    public function test_legacy_non_pkg6_sales_operation_continues_to_allow_variance_assign(): void
    {
        // Reconcile legacy op with a 50.00 SAR shortfall (-5000 halalas)
        $this->acc()->patchJson("/api/v1/company/me/operations/{$this->legacyOperation->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 45000],
            ],
        ])->assertOk();

        $this->assertSame(-5000, $this->legacyOperation->fresh()->payload['varianceHalalas']);

        // Legitimate legacy allocation
        $response = $this->acc()->postJson("/api/v1/company/me/operations/{$this->legacyOperation->id}/sales-variance/assign", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 5000],
            ],
            'notes' => 'Legitimate legacy variance allocation',
        ]);

        $response->assertOk()
            ->assertJsonPath('operationId', $this->legacyOperation->id)
            ->assertJsonPath('varianceTotalHalalas', 5000);

        // Exactly 1 EmployeeMovement debit recorded
        $movement = EmployeeMovement::where('ref_operation_id', $this->legacyOperation->id)->sole();
        $this->assertSame($this->employee->id, $movement->employee_id);
        $this->assertSame(5000, $movement->amount);
        $this->assertSame('debit', $movement->movement_type);
        $this->assertSame('sales_variance', $movement->category);
    }

    /**
     * Requirement 2: Reliable server-side predicate test.
     * Proves isPkg6DailyReport identifies PKG-6 reports across authoritative server indicators
     * (pkg6Review, managerShiftId, reportReview), without falsely classifying cashier shifts
     * or non-PKG-6 operations that carry cashier shift IDs or standard origins.
     */
    public function test_detection_criteria_identifies_pkg6_reports_via_authoritative_relationships(): void
    {
        // 1. With pkg6Review metadata (from BridgeManagerDailyClose)
        $opWithPkg6Review = new Operation([
            'module_key' => 'sales',
            'origin' => 'mobile',
            'payload' => ['pkg6Review' => ['phase' => 'accountant_review']],
        ]);
        $this->assertTrue($opWithPkg6Review->isPkg6DailyReport(), 'Identified by payload.pkg6Review');

        // 2. With managerShiftId linking to BranchManagerShift (PKG-6 daily close)
        $opWithManagerShiftId = new Operation([
            'module_key' => 'sales',
            'origin' => 'mobile',
            'payload' => ['managerShiftId' => (string) Str::uuid()],
        ]);
        $this->assertTrue($opWithManagerShiftId->isPkg6DailyReport(), 'Identified by payload.managerShiftId');

        // 3. With reportReview presentation structure
        $opWithReportReview = new Operation([
            'module_key' => 'sales',
            'payload' => ['reportReview' => ['phase' => 'head_review']],
        ]);
        $this->assertTrue($opWithReportReview->isPkg6DailyReport(), 'Identified by payload.reportReview');

        // 4. Cashier shift link alone (shiftId / legacyShiftId) is NOT a PKG-6 daily report
        $linkedShiftOp = new Operation([
            'module_key' => 'sales',
            'payload' => ['shiftId' => (string) Str::uuid(), 'legacyShiftId' => '12345'],
        ]);
        $this->assertFalse($linkedShiftOp->isPkg6DailyReport(), 'Cashier shift links alone are NOT a PKG-6 daily report');

        // 5. Standard mobile origin without manager shift is NOT a PKG-6 daily report
        $mobileOp = new Operation([
            'module_key' => 'sales',
            'origin' => 'mobile',
            'payload' => ['totalHalalas' => 50000],
        ]);
        $this->assertFalse($mobileOp->isPkg6DailyReport(), 'Standard mobile sales upload is NOT a PKG-6 daily report');

        // 6. Standard legacy non-PKG-6 operation must return false
        $standardLegacyOp = new Operation([
            'module_key' => 'sales',
            'origin' => 'excel',
            'source_module' => 'excel_upload',
            'payload' => ['totalHalalas' => 50000],
        ]);
        $this->assertFalse($standardLegacyOp->isPkg6DailyReport(), 'Standard legacy sales op is NOT PKG-6');
    }

    /**
     * Requirement 1 & 4: Regression test proving a legacy sales operation that contains
     * cashier shift links (shiftId / legacyShiftId) without being a PKG-6 manager report
     * is NOT falsely blocked and can be reconciled and variance-assigned normally.
     */
    public function test_legacy_sales_operation_with_cashier_shift_links_is_permitted(): void
    {
        $legacyWithShiftLink = Operation::create([
            'public_id' => 'SAL-LNK-' . strtoupper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'sales',
            'origin' => 'mobile',
            'source_module' => 'mobile_pos',
            'amount' => 30000,
            'match' => 'diff',
            'diff_note' => 'عجز قديم',
            'status' => Operation::STATUS_PENDING,
            'payload' => [
                'date' => today()->toDateString(),
                'totalHalalas' => 30000,
                'shiftId' => (string) Str::uuid(),
                'legacyShiftId' => 'cashier_shift_99',
            ],
            'operation_date' => now(),
            'submitted_at' => now(),
        ]);

        // 1. PATCH sales-details must succeed (200 OK)
        $patchResponse = $this->acc()->patchJson("/api/v1/company/me/operations/{$legacyWithShiftLink->id}/sales-details", [
            'channels' => [
                ['key' => 'cash', 'actualAmountHalalas' => 20000, 'posAmountHalalas' => 25000],
                ['key' => 'bank', 'actualAmountHalalas' => 5000, 'posAmountHalalas' => 5000],
            ],
            'notes' => 'Legacy reconciliation with shift link',
        ]);
        $patchResponse->assertStatus(200);

        // 2. POST sales-variance/assign must succeed (200 OK) and create movement
        $assignResponse = $this->acc()->postJson("/api/v1/company/me/operations/{$legacyWithShiftLink->id}/sales-variance/assign", [
            'allocations' => [
                ['empNumber' => '1001', 'amountHalalas' => 5000],
            ],
            'notes' => 'Allocating legacy variance with shift link',
        ]);
        $assignResponse->assertStatus(200);

        // Movement created
        $this->assertSame(1, EmployeeMovement::where('ref_operation_id', $legacyWithShiftLink->id)->count());
    }
}
