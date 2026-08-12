<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Branch\Models\Branch;
use Modules\Shift\Services\BranchWorkdayWindowService;
use Tests\TestCase;

/**
 * Meeting 2026-08-11, توقا كافية:
 *  - «لم أخصّص الشفتات لهذا الفرع، من أين ظهر هذا الشفت؟» — the app rendered the
 *    hardcoded 09:00–17:00 fallback as though it were a configured shift,
 *    because a branch created AFTER the brand's shift config was saved never
 *    received its templates;
 *  - «خصّصنا المطعم للمحاسب ولم يظهر في داشبورده» — the assignment chain has
 *    four links and a break in any of them renders as an empty screen.
 */
class NewBranchShiftsAndScopeTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@shift-scope.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'توقا', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'توقا كافية', 'abbr' => 'TQ',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'توقا السليمانية', 'status' => 'active',
        ]);

        // «3 شفت × 8 ساعات، تبدأ 06:00» — saved on the brand BEFORE the branch exists.
        BrandShiftConfig::create([
            'brand_id' => $this->brand->id, 'num_shifts' => 3,
            'duration_hours' => 8, 'first_shift_start' => '06:00',
        ]);
    }

    public function test_a_branch_created_after_the_config_gets_its_shift_templates(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/v1/admin/restaurants/{$this->restaurant->id}/branches",
            ['name' => 'توقا السليمانية 1', 'city' => 'الرياض'],
        );
        $response->assertCreated();
        $branchId = $response->json('id');

        $templates = DB::table('shifts')->where('branch_id', $branchId)->where('is_active', true)->get();
        $this->assertCount(3, $templates, 'a new branch must inherit its brand schedule, not fall back to 09:00–17:00');

        // …and the manager workday is the configured one, flagged as real.
        $workday = app(BranchWorkdayWindowService::class)->forBranch($branchId);
        $this->assertSame('06:00', $workday['start']);
        $this->assertSame(3, $workday['shiftCount']);
        $this->assertFalse($workday['isFallback']);
    }

    /** A branch with no templates still renders, but says so. */
    public function test_the_fallback_workday_is_flagged_instead_of_posing_as_a_shift(): void
    {
        $orphan = Branch::factory()->create(['asab_company_id' => $this->company->id]);

        $workday = app(BranchWorkdayWindowService::class)->forBranch($orphan->id);

        $this->assertSame('09:00', $workday['start']);
        $this->assertSame(0, $workday['shiftCount']);
        $this->assertTrue($workday['isFallback'], 'the screen must be able to tell a real shift from a placeholder');
    }

    /** The accountant doctor names the broken link instead of guessing. */
    public function test_the_accountant_doctor_reports_a_healthy_assignment(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'توقا السليمانية 1',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        $this->assertNotNull($branch->id);

        $accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'نزار عبد القادر',
            'email' => 'nizar@scope.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'restaurant',
            'restaurant_ids' => [$this->restaurant->id], 'module_keys' => ['inventory'],
        ]);

        $this->artisan('asab:accountant-doctor', ['--email' => 'nizar@scope.test'])
            ->expectsOutputToContain('توقا كافية')
            ->assertExitCode(0);
    }

    /** A restaurant assigned to an accountant of ANOTHER company is named as such. */
    public function test_the_doctor_flags_an_accountant_with_no_company_at_all(): void
    {
        $accountant = AsabUser::create([
            'name' => 'محاسب بلا شركة', 'email' => 'orphan@scope.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'brand']);

        $this->artisan('asab:accountant-doctor', ['--email' => 'orphan@scope.test'])
            ->expectsOutputToContain('asab:repair-user-companies')
            ->assertExitCode(0);
    }

    public function test_an_unknown_account_is_a_failure(): void
    {
        $this->artisan('asab:accountant-doctor', ['--email' => 'nobody@scope.test'])->assertExitCode(1);
    }
}
