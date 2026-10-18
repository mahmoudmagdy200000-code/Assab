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
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * COR-05-03: Real Backend HTTP tests for employee pagination and search contract.
 *
 * Verifies with >100 synthetic employees:
 * 1. Pagination across multiple branches (>100 in target branch, page capping at 100, page 2 navigation).
 * 2. Name search via 'q' parameter searches employee names and NEVER searches employee numbers.
 * 3. Exact employee number search via 'empNumber' parameter.
 * 4. Strict tenant and branch isolation across multiple branches and companies.
 */
class EmployeePaginationAndSearchHttpTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;
    private Branch $branchA;
    private Branch $branchB;
    private AsabUser $accountantA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create([
            'name' => 'Company A Synthetic Corp',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brandA = AsabBrand::create([
            'company_id' => $this->companyA->id,
            'name' => 'Brand Alpha',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branchA = Branch::factory()->create([
            'name' => 'Branch A Main',
            'asab_brand_id' => $brandA->id,
            'asab_company_id' => $this->companyA->id,
        ]);

        $this->branchB = Branch::factory()->create([
            'name' => 'Branch B Secondary',
            'asab_brand_id' => $brandA->id,
            'asab_company_id' => $this->companyA->id,
        ]);

        $this->accountantA = AsabUser::create([
            'company_id' => $this->companyA->id,
            'name' => 'Lead Accountant',
            'email' => 'accountant@synthetic.test',
            'password' => 'secret-synthetic-hash',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountantA->id,
            'role_key' => 'accountant',
            'scope' => 'all',
            'branch_ids' => [],
        ]);

        // Seed 120 synthetic employees in Branch A
        // 60 named "Ahmed {i}", 60 named "Khaled {i}"
        $employeesBranchA = [];
        for ($i = 1; $i <= 120; $i++) {
            $prefix = ($i <= 60) ? 'Ahmed' : 'Khaled';
            $num = sprintf('EMP-A%03d', $i);
            $employeesBranchA[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $this->companyA->id,
                'branch_id' => $this->branchA->id,
                'emp_number' => $num,
                'name' => "{$prefix} Employee {$i}",
                'role' => 'كاشير',
                'phone' => sprintf('+96650000%04d', $i),
                'monthly_salary' => 500000,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Employee::insert($employeesBranchA);

        // Seed 30 synthetic employees in Branch B
        $employeesBranchB = [];
        for ($j = 1; $j <= 30; $j++) {
            $num = sprintf('EMP-B%03d', $j);
            $employeesBranchB[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $this->companyA->id,
                'branch_id' => $this->branchB->id,
                'emp_number' => $num,
                'name' => "Branch B Worker {$j}",
                'role' => 'كاشير',
                'phone' => sprintf('+96651111%04d', $j),
                'monthly_salary' => 450000,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Employee::insert($employeesBranchB);
    }

    public function test_cor_05_03_more_than_100_synthetic_employees_branch_isolation_and_pagination(): void
    {
        // 1. Page 1 request with pageSize=100 on Branch A (which has 120 employees)
        $resPage1 = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}&pageSize=100&page=1")
            ->assertOk();

        $body1 = $resPage1->json();
        $this->assertCount(100, $body1['data'], 'Page 1 returns capped max 100 items');
        $this->assertSame(1, $body1['meta']['page']);
        $this->assertSame(100, $body1['meta']['pageSize']);
        $this->assertSame(120, $body1['meta']['total'], 'Total employees in Branch A is exactly 120');
        $this->assertSame(2, $body1['meta']['totalPages'], '120 items at pageSize 100 results in 2 pages');

        // Verify all items belong strictly to Branch A
        foreach ($body1['data'] as $emp) {
            $this->assertSame($this->branchA->id, $emp['branchId']);
            $this->assertSame('Branch A Main', $emp['branchName']);
        }

        // 2. Page 2 request: returns remaining 20 employees
        $resPage2 = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}&pageSize=100&page=2")
            ->assertOk();

        $body2 = $resPage2->json();
        $this->assertCount(20, $body2['data'], 'Page 2 returns remaining 20 items');
        $this->assertSame(2, $body2['meta']['page']);
        $this->assertSame(120, $body2['meta']['total']);

        // Verify page 1 and page 2 are disjoint
        $idsPage1 = collect($body1['data'])->pluck('id')->all();
        $idsPage2 = collect($body2['data'])->pluck('id')->all();
        $intersection = array_intersect($idsPage1, $idsPage2);
        $this->assertEmpty($intersection, 'Page 1 and Page 2 contain mutually exclusive employees');
        $this->assertCount(120, array_unique(array_merge($idsPage1, $idsPage2)), 'Combined pages cover all 120 Branch A employees');
    }

    public function test_cor_05_03_name_search_q_searches_only_names_not_emp_number(): void
    {
        // 1. Search for 'Ahmed' in Branch A -> exactly 60 employees
        $resAhmed = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}&q=Ahmed&pageSize=100")
            ->assertOk();

        $bodyAhmed = $resAhmed->json();
        $this->assertSame(60, $bodyAhmed['meta']['total'], "q=Ahmed matches exactly the 60 employees with 'Ahmed' in name");
        $this->assertCount(60, $bodyAhmed['data']);
        foreach ($bodyAhmed['data'] as $emp) {
            $this->assertStringContainsString('Ahmed', $emp['name']);
        }

        // 2. Search by employee number using 'q' filter -> returns ZERO matches because 'q' searches 'name'
        $resEmpNumViaQ = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}&q=EMP-A001&pageSize=100")
            ->assertOk();

        $this->assertSame(0, $resEmpNumViaQ->json('meta.total'), "Backend 'q' searches only 'name' and does not match 'emp_number'");
        $this->assertEmpty($resEmpNumViaQ->json('data'));

        // 3. Search by employee number using dedicated 'empNumber' filter -> returns exactly 1 match
        $resExactNum = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchA->id}&empNumber=EMP-A001")
            ->assertOk();

        $this->assertSame(1, $resExactNum->json('meta.total'));
        $this->assertCount(1, $resExactNum->json('data'));
        $this->assertSame('EMP-A001', $resExactNum->json('data.0.empNumber'));
        $this->assertSame('Ahmed Employee 1', $resExactNum->json('data.0.name'));
    }

    public function test_cor_05_03_multiple_branches_and_cross_branch_isolation(): void
    {
        // Branch B query returns exactly 30 employees, none from Branch A
        $resBranchB = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson("/api/v1/company/me/employees?branchId={$this->branchB->id}&pageSize=100")
            ->assertOk();

        $bodyB = $resBranchB->json();
        $this->assertSame(30, $bodyB['meta']['total'], 'Branch B has exactly 30 synthetic employees');
        $this->assertCount(30, $bodyB['data']);

        foreach ($bodyB['data'] as $emp) {
            $this->assertSame($this->branchB->id, $emp['branchId']);
            $this->assertSame('Branch B Secondary', $emp['branchName']);
            $this->assertStringStartsWith('EMP-B', $emp['empNumber']);
        }
    }

    public function test_cor_05_03_tenant_isolation_hides_foreign_company_employees(): void
    {
        $foreignCompany = AsabCompany::create([
            'name' => 'Foreign Corp',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);
        $foreignBrand = AsabBrand::create([
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $foreignBranch = Branch::factory()->create([
            'name' => 'Foreign Branch',
            'asab_brand_id' => $foreignBrand->id,
            'asab_company_id' => $foreignCompany->id,
        ]);
        Employee::create([
            'company_id' => $foreignCompany->id,
            'branch_id' => $foreignBranch->id,
            'emp_number' => 'EMP-FOREIGN-01',
            'name' => 'Foreign Employee',
            'role' => 'كاشير',
            'monthly_salary' => 500000,
            'status' => 'active',
        ]);

        // Company A accountant queries all employees (no branch filter)
        $resAll = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson('/api/v1/company/me/employees?pageSize=100')
            ->assertOk();

        // 120 in Branch A + 30 in Branch B = 150 total for Company A. Foreign company's employee is never included.
        $this->assertSame(150, $resAll->json('meta.total'));
        $allIds = collect($resAll->json('data'))->pluck('empNumber')->all();
        $this->assertNotContains('EMP-FOREIGN-01', $allIds, 'Foreign company employee is strictly isolated and never visible');
    }
}
