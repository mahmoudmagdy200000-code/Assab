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
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «أين رفع موظفي الفروع لكل فرع؟»: the setup screen builds a
 * brand branch by branch, but the roster had a per-RESTAURANT entry point only.
 * A newly added branch had nowhere to upload its own staff, and no per-branch
 * «حالة الرفع» to show for it.
 */
class BranchEmployeesUploadTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@roster.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'برجر بيت', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'الرياض', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'برجر بيت — فرع العليا',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
    }

    private function rosterCsv(string $rows): string
    {
        return "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين,اسم الفرع'."\n".$rows;
    }

    private function upload(string $url, string $contents)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent('employees.csv', $contents)]);
    }

    public function test_a_branch_can_upload_its_own_roster(): void
    {
        $this->upload(
            "/api/v1/admin/branches/{$this->branch->id}/upload/employees",
            $this->rosterCsv('أحمد,مشرف,0500000001,1010101010,"5,000",صباحية,2026-01-01,'."\n")
        )
            ->assertStatus(200)
            ->assertJsonPath('employeeCount', 1)
            ->assertJsonPath('errors', []);

        $employee = Employee::firstWhere('name', 'أحمد');
        $this->assertNotNull($employee);
        $this->assertSame($this->branch->id, $employee->branch_id);
        $this->assertSame($this->company->id, $employee->company_id);
        // Sheet is in riyals, storage is halalas.
        $this->assertSame(500000, (int) $employee->monthly_salary);
    }

    /** A blank «اسم الفرع» is fine; a DIFFERENT branch name must not be misfiled. */
    public function test_a_row_naming_another_branch_is_a_row_error(): void
    {
        $this->upload(
            "/api/v1/admin/branches/{$this->branch->id}/upload/employees",
            $this->rosterCsv(
                'أحمد,مشرف,0500000001,,"5,000",صباحية,2026-01-01,برجر بيت — فرع العليا'."\n".
                'سالم,محاسب,0500000002,,"6,000",مسائية,2026-01-01,برجر بيت — فرع المنقا'."\n"
            )
        )
            ->assertStatus(200)
            ->assertJsonPath('employeeCount', 1)
            ->assertJsonPath('errors.0.row', 3);

        $this->assertNull(Employee::firstWhere('name', 'سالم'));
    }

    public function test_the_branch_status_reports_the_roster_column(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$this->branch->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('employees', false)
            ->assertJsonPath('employeesStatus', 'not_uploaded');

        $this->upload(
            "/api/v1/admin/branches/{$this->branch->id}/upload/employees",
            $this->rosterCsv('أحمد,مشرف,0500000001,,"5,000",صباحية,2026-01-01,'."\n")
        )->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$this->branch->id}/upload-status")
            ->assertJsonPath('employees', true)
            ->assertJsonPath('employeesStatus', 'done')
            ->assertJsonPath('employeesCount', 1)
            // Assets still missing → half the branch's datasets are in.
            // `completionPct` keeps its old assets-only meaning.
            ->assertJsonPath('overallCompletionPct', 50)
            ->assertJsonPath('completionPct', 0);
    }

    public function test_the_brand_branches_column_carries_every_branchs_roster_state(): void
    {
        $this->upload(
            "/api/v1/admin/branches/{$this->branch->id}/upload/employees",
            $this->rosterCsv('أحمد,مشرف,0500000001,,"5,000",صباحية,2026-01-01,'."\n")
        )->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/brands/{$this->brand->id}/branches/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('branches.0.branchId', $this->branch->id)
            ->assertJsonPath('branches.0.employees', true)
            ->assertJsonPath('totals.employeesUploaded', 1);
    }

    /** A cashier row is still refused — those accounts are made in the app. */
    public function test_a_cashier_row_is_refused(): void
    {
        $this->upload(
            "/api/v1/admin/branches/{$this->branch->id}/upload/employees",
            $this->rosterCsv('خالد,كاشير,0500000003,,"4,000",صباحية,2026-01-01,'."\n")
        )->assertStatus(422);

        $this->assertSame(0, Employee::count());
    }
}
