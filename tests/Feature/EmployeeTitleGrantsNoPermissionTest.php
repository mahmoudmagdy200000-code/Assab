<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * BRC v3.4 F-06 — OPS-10 / AC-60.
 *
 * OPS-10: an employee TITLE (e.g. Head Cashier / Senior Cashier) is a label, not a permission. It must
 * never create credentials, a dashboard role or branch-manager rights on its own.
 *
 * Evidence class: CHARACTERIZATION of behaviour that already conforms (SQLite, REVIEWER_EXECUTED) —
 * there is no RED state because the repository has no title→permission mapping (`asab_employees.role` is
 * free text; the only role-string matcher is `CashierRole`, which REFUSES an exact "cashier" and grants
 * nothing). The test pins that absence so a future change cannot introduce an implicit grant silently.
 */
class EmployeeTitleGrantsNoPermissionTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Titles Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $company->id]);
        BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = AsabUser::create([
            'company_id' => $company->id, 'name' => 'مدير الفرع', 'email' => 'titles-bm@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->manager->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branch->id],
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function titles(): array
    {
        return [
            'head cashier' => ['Head Cashier'],
            'senior cashier' => ['Senior Cashier'],
            'arabic head cashier' => ['رئيس الكاشير'],
            'arabic senior cashier' => ['كبير الكاشير'],
            // a free-text title that happens to spell a DASHBOARD role key must not become that role either
            'dashboard role key as text' => ['head'],
            'branch-manager as text' => ['Branch Manager'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function test_a_title_creates_no_credentials_role_or_manager_rights(string $title): void
    {
        $users = AsabUser::withoutGlobalScopes()->count();
        $roles = AsabUserRole::count();
        $managers = BranchManager::withTrashed()->count();
        $cashiers = Cashier::withTrashed()->count();

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch/employees', [
            'empNumber' => 'EMP-T-1', 'name' => 'موظف بلقب', 'role' => $title, 'monthlySalary' => 400000,
        ])->assertSuccessful();

        $employee = Employee::withoutGlobalScopes()->where('emp_number', 'EMP-T-1')->firstOrFail();
        $this->assertSame($title, $employee->role, 'the title is stored as a plain label');
        $this->assertNull($employee->asab_user_id, 'a title must not link the employee to a dashboard login');
        $this->assertNull($employee->legacy_cashier_id, 'a title must not provision a mobile cashier');

        $this->assertSame($users, AsabUser::withoutGlobalScopes()->count(), 'no dashboard user was created');
        $this->assertSame($roles, AsabUserRole::count(), 'no dashboard role was granted');
        $this->assertSame($managers, BranchManager::withTrashed()->count(), 'no branch-manager credentials were created');
        $this->assertSame($cashiers, Cashier::withTrashed()->count(), 'no cashier credentials were created');
    }
}
