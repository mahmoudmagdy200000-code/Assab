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
 * The two «حالة الرفع» columns on المطاعم والفروع that had no working endpoint:
 * the per-branch fixed-assets state (written but unreadable) and the per-restaurant
 * employees roster (template and upload both missing entirely).
 */
class AdminOnboardingUploadTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin',
            'email' => 'admin@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
        $this->company = AsabCompany::create(['name' => 'Bazooka', 'plan' => 'Basic', 'status' => 'active']);
    }

    private function restaurant(): AsabRestaurant
    {
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'بازوكا',
            'abbr' => 'BZ',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        return AsabRestaurant::create([
            'company_id' => $this->company->id,
            'brand_id' => $brand->id,
            'name' => 'مطعم بازوكا 1',
            'status' => 'active',
        ]);
    }

    private function upload(string $url, string $filename, string $contents)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent($filename, $contents)]);
    }

    // ---- per-branch fixed-assets status: written by the upload, never readable ----

    public function test_branch_upload_status_reports_a_fixed_assets_upload(): void
    {
        $branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);

        $csv = "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .'ثلاجة,معدات مطبخ,,INV-1,"10,000.00",60,أحمد,'."\n";

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", 'assets.csv', $csv)
            ->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$branch->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('fixedAssets', true)
            ->assertJsonPath('completionPct', 100)
            ->assertJsonPath('uploads.0.type', 'fixed-assets')
            ->assertJsonPath('uploads.0.status', 'done');
    }

    public function test_branch_upload_status_is_empty_before_any_upload(): void
    {
        $branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/branches/{$branch->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('fixedAssets', false)
            ->assertJsonPath('completionPct', 0)
            ->assertJsonPath('uploads', []);
    }

    /** The plural alias existed for brands only, so a plural FE call 404'd. */
    public function test_branch_fixed_assets_accepts_the_plural_uploads_path(): void
    {
        $branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);

        $csv = "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n"
            .'فرن,معدات مطبخ,,INV-2,"5,000.00",36,سعيد,'."\n";

        $this->upload("/api/v1/admin/branches/{$branch->id}/uploads/fixed-assets", 'assets.csv', $csv)
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1);
    }

    // ---- employees template + per-restaurant roster upload ----

    public function test_employees_template_is_downloadable(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/admin/upload/templates/employees?format=csv');

        $res->assertStatus(200);
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('اسم الموظف', $res->getContent());
    }

    public function test_employees_upload_creates_roster_rows_scoped_to_the_restaurant(): void
    {
        $restaurant = $this->restaurant();
        $branch = Branch::factory()->create([
            'name' => 'فرع مكة 1',
            'asab_company_id' => $this->company->id,
            'asab_restaurant_id' => $restaurant->id,
        ]);

        $csv = "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,اسم الفرع,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين'."\n"
            .'خالد,طباخ,فرع مكة 1,0501112233,1010101010,"4,500.00",صباحية,2026-01-15'."\n";

        $this->upload("/api/v1/admin/restaurants/{$restaurant->id}/upload/employees", 'emps.csv', $csv)
            ->assertStatus(200)
            ->assertJsonPath('employeeCount', 1)
            ->assertJsonPath('errors', []);

        $employee = Employee::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('خالد', $employee->name);
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame($this->company->id, $employee->company_id);
        // Riyals in the sheet, halalas in the column — same unit the one-by-one
        // add stores, so the two entry points cannot disagree.
        $this->assertSame(450000, $employee->monthly_salary);
        $this->assertSame('EMP-0001', $employee->emp_number);
    }

    /**
     * The fixed-assets importer silently nulls an unmatched «اسم الفرع». A roster
     * row that lands on no branch is invisible to the branch screens, so this
     * importer reports it instead.
     */
    public function test_employees_upload_reports_a_branch_name_outside_the_restaurant(): void
    {
        $restaurant = $this->restaurant();
        Branch::factory()->create([
            'name' => 'فرع مكة 1',
            'asab_company_id' => $this->company->id,
            'asab_restaurant_id' => $restaurant->id,
        ]);
        // Belongs to the company but to a different restaurant: must not match.
        Branch::factory()->create([
            'name' => 'فرع الرياض 1',
            'asab_company_id' => $this->company->id,
            'asab_restaurant_id' => $this->restaurant()->id,
        ]);

        $csv = "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,اسم الفرع,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين'."\n"
            .'سالم,طباخ,فرع الرياض 1,0501112233,,3000,صباحية,'."\n";

        $this->upload("/api/v1/admin/restaurants/{$restaurant->id}/upload/employees", 'emps.csv', $csv)
            ->assertStatus(200)
            ->assertJsonPath('employeeCount', 0)
            ->assertJsonPath('errors.0.row', 2);

        $this->assertSame(0, Employee::withoutGlobalScopes()->count());
    }

    public function test_employees_upload_rejects_a_header_that_matches_no_layout(): void
    {
        $restaurant = $this->restaurant();

        $this->upload(
            "/api/v1/admin/restaurants/{$restaurant->id}/upload/employees",
            'emps.csv',
            "\xEF\xBB\xBF".'foo,bar'."\n".'1,2'."\n",
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_HEADER');
    }

    public function test_restaurant_upload_status_reports_the_employees_roster(): void
    {
        $restaurant = $this->restaurant();
        Branch::factory()->create([
            'name' => 'فرع مكة 1',
            'asab_company_id' => $this->company->id,
            'asab_restaurant_id' => $restaurant->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/restaurants/{$restaurant->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('employees', false);

        $csv = "\xEF\xBB\xBF".'اسم الموظف,الوظيفة,اسم الفرع,رقم الجوال,رقم الهوية,الراتب الشهري (ر.س),نوع الوردية,تاريخ التعيين'."\n"
            .'خالد,طباخ,فرع مكة 1,0501112233,,3000,صباحية,'."\n";

        $this->upload("/api/v1/admin/restaurants/{$restaurant->id}/upload/employees", 'emps.csv', $csv)
            ->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/restaurants/{$restaurant->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('employees', true)
            ->assertJsonPath('completionPct', 100);
    }
}
