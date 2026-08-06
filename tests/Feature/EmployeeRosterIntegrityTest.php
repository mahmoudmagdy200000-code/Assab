<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Meeting 2026-08-06 «كشف حساب الموظفين غير صحيح — أسماء مكررة، ومدراء الفروع
 * غير موجودين»: the roster importer created a fresh employee on every upload,
 * and the template ships the SAVED rows, so «حمّل → صحّح → ارفع» tripled the
 * list. Branch managers were never on it at all.
 */
class EmployeeRosterIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabRestaurant $restaurant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@roster-integrity.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'جورمية كافيه', 'abbr' => 'GC',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $brand->id, 'name' => 'الرياض', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'التعاون 1',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
    }

    private function upload(string $rows)
    {
        $csv = "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين,اسم الفرع'."\n".$rows;

        return $this->actingAs($this->admin, 'sanctum')->post(
            "/api/v1/admin/restaurants/{$this->restaurant->id}/upload/employees",
            ['file' => UploadedFile::fake()->createWithContent('employees.csv', $csv)],
        );
    }

    public function test_re_uploading_the_same_roster_does_not_duplicate_anyone(): void
    {
        $rows = 'أحمد محمود السيد,طباخ برجر,0551111111,1010101010,4000,صباحي,2026-01-05,التعاون 1'."\n"
            .'إدريس عثمان أحمد,عامل نظافة,0552222222,2020202020,3000,مسائي,2026-02-10,التعاون 1';

        $this->upload($rows)->assertSuccessful();
        $this->upload($rows)->assertSuccessful();
        $this->upload($rows)->assertSuccessful();

        $this->assertSame(2, Employee::withoutGlobalScope('tenant')->count(), 'three uploads of one roster must leave one row per person');
    }

    /** A re-upload is the documented edit path: changed values must land. */
    public function test_a_re_upload_updates_the_existing_row_and_keeps_its_number(): void
    {
        $this->upload('أحمد محمود السيد,طباخ برجر,0551111111,1010101010,4000,صباحي,2026-01-05,التعاون 1')
            ->assertSuccessful();
        $number = Employee::withoutGlobalScope('tenant')->value('emp_number');

        $this->upload('أحمد محمود السيد,شيف تنفيذي,0559999999,1010101010,6500,مسائي,2026-01-05,التعاون 1')
            ->assertSuccessful();

        $employee = Employee::withoutGlobalScope('tenant')->sole();
        $this->assertSame('شيف تنفيذي', $employee->role);
        $this->assertSame(650000, $employee->monthly_salary);
        $this->assertSame($number, $employee->emp_number, 'the employee number is their identity on statements');
    }

    /** Same person, no national id in the sheet — matched on name within branch. */
    public function test_a_codeless_row_is_matched_by_name_within_the_branch(): void
    {
        $row = 'بدر صالح العتيبي,مشرف وردية,0553333333,,5000,صباحي,2026-03-01,التعاون 1';

        $this->upload($row)->assertSuccessful();
        $this->upload($row)->assertSuccessful();

        $this->assertSame(1, Employee::withoutGlobalScope('tenant')->count());
    }

    public function test_the_repair_command_removes_legacy_duplicates(): void
    {
        foreach (['EMP-0001', 'EMP-0002', 'EMP-0003'] as $number) {
            Employee::create([
                'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
                'emp_number' => $number, 'name' => 'أحمد محمود السيد', 'role' => 'طباخ برجر',
                'monthly_salary' => 400000, 'hire_date' => now(), 'status' => 'active',
            ]);
        }

        $this->artisan('asab:repair-employees', ['--skip-managers' => true])->assertExitCode(0);

        $rows = Employee::withoutGlobalScope('tenant')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('EMP-0001', $rows->first()->emp_number, 'the original row survives');
    }

    /** A duplicate that carries ledger movements is a business decision, not ours. */
    public function test_a_duplicate_with_movements_is_left_alone(): void
    {
        $original = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-0001', 'name' => 'إدريس عثمان أحمد', 'role' => 'عامل نظافة',
            'monthly_salary' => 300000, 'hire_date' => now(), 'status' => 'active',
        ]);
        $duplicate = Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-0002', 'name' => 'إدريس عثمان أحمد', 'role' => 'عامل نظافة',
            'monthly_salary' => 300000, 'hire_date' => now(), 'status' => 'active',
        ]);
        EmployeeMovement::create([
            'employee_id' => $duplicate->id, 'movement_date' => now(), 'description' => 'سلفة',
            'movement_type' => 'debit', 'category' => 'advance', 'amount' => 50000,
        ]);

        $this->artisan('asab:repair-employees', ['--skip-managers' => true])->assertExitCode(0);

        $this->assertCount(2, Employee::withoutGlobalScope('tenant')->get());
        $this->assertNotNull($original->fresh());
        $this->assertNotNull($duplicate->fresh());
    }

    public function test_branch_managers_get_an_employee_record(): void
    {
        $manager = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'قدورة', 'email' => 'qadoura@roster.test',
            'phone' => '0554444444', 'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->branch->forceFill(['asab_manager_user_id' => $manager->id])->save();

        $this->artisan('asab:repair-employees')->assertExitCode(0);

        $row = Employee::withoutGlobalScope('tenant')->where('name', 'قدورة')->sole();
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame('مدير فرع', $row->role);

        // …and running it twice does not add a second one.
        $this->artisan('asab:repair-employees')->assertExitCode(0);
        $this->assertSame(1, Employee::withoutGlobalScope('tenant')->where('name', 'قدورة')->count());
    }

    /**
     * Production reality: the branch's manager exists as a MOBILE login only —
     * `branches.asab_manager_user_id` is null — and the first pass created 0
     * records for exactly those branches (2026-08-06).
     */
    public function test_a_manager_known_only_to_the_mobile_world_is_added_too(): void
    {
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'زكريا صبري 2', 'phone' => '055854475', 'branch_id' => $this->branch->id,
        ]);
        $this->assertNull($this->branch->fresh()->asab_manager_user_id, 'precondition: no dashboard assignment');

        $this->artisan('asab:repair-employees')->assertExitCode(0);

        $row = Employee::withoutGlobalScope('tenant')->where('name', 'زكريا صبري 2')->sole();
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame('مدير فرع', $row->role);

        $this->artisan('asab:repair-employees')->assertExitCode(0);
        $this->assertSame(1, Employee::withoutGlobalScope('tenant')->where('name', 'زكريا صبري 2')->count());
    }

    /** The undo pass removes what it created and nothing else. */
    public function test_undo_managers_removes_only_untouched_manager_rows(): void
    {
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'مدير برجر بيت — فرع العليا', 'phone' => '0557777777', 'branch_id' => $this->branch->id,
        ]);
        $this->artisan('asab:repair-employees')->assertExitCode(0);

        // An uploaded employee (real salary) must survive the undo.
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-9001', 'name' => 'موظف مرفوع', 'role' => 'مدير فرع',
            'monthly_salary' => 800000, 'hire_date' => now(), 'status' => 'active',
        ]);

        $this->artisan('asab:repair-employees', ['--undo-managers' => true])->assertExitCode(0);

        $live = Employee::withoutGlobalScope('tenant')->whereNull('deleted_at')->pluck('name');
        $this->assertFalse($live->contains('مدير برجر بيت — فرع العليا'));
        $this->assertTrue($live->contains('موظف مرفوع'), 'an uploaded employee is never removed');
    }

    /**
     * Demo brands share this database with the live ones, so an unscoped pass
     * touches both — and an unscoped undo then removed the REAL managers along
     * with the demo ones (2026-08-06).
     */
    public function test_the_run_can_be_limited_to_one_branch(): void
    {
        $otherBranch = Branch::factory()->create([
            'name' => 'برجر بيت — فرع العليا',
            'asab_company_id' => $this->company->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'قدورة', 'phone' => '0545444444', 'branch_id' => $this->branch->id,
        ]);
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'مدير ديمو', 'phone' => '0559999999', 'branch_id' => $otherBranch->id,
        ]);

        $this->artisan('asab:repair-employees', ['--branch' => $this->branch->id])->assertExitCode(0);

        $names = Employee::withoutGlobalScope('tenant')->whereNull('deleted_at')->pluck('name');
        $this->assertTrue($names->contains('قدورة'));
        $this->assertFalse($names->contains('مدير ديمو'), 'a branch outside the scope must be untouched');

        // …and the undo respects the same scope.
        $this->artisan('asab:repair-employees', ['--undo-managers' => true, '--branch' => $otherBranch->id])
            ->assertExitCode(0);
        $this->assertTrue(
            Employee::withoutGlobalScope('tenant')->whereNull('deleted_at')->pluck('name')->contains('قدورة'),
            'undoing another branch must not remove this one',
        );
    }

    /** An unmatched scope is a failure, not a silent full-database run. */
    public function test_an_unmatched_scope_fails_instead_of_running_everywhere(): void
    {
        $this->artisan('asab:repair-employees', ['--brand' => 'no-such-brand'])->assertExitCode(1);
    }

    /** A manager already on the uploaded roster is not added a second time. */
    public function test_a_manager_already_in_the_roster_is_not_duplicated(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => 'EMP-0001', 'name' => 'قدورة', 'phone' => '0545444444',
            'role' => 'مدير الفرع', 'monthly_salary' => 900000, 'hire_date' => now(), 'status' => 'active',
        ]);
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'قدورة', 'phone' => '0545444444', 'branch_id' => $this->branch->id,
        ]);

        $this->artisan('asab:repair-employees')->assertExitCode(0);

        $this->assertSame(1, Employee::withoutGlobalScope('tenant')->where('name', 'قدورة')->count());
        // …and the uploaded salary/role are untouched.
        $row = Employee::withoutGlobalScope('tenant')->where('name', 'قدورة')->sole();
        $this->assertSame(900000, $row->monthly_salary);
    }
}
