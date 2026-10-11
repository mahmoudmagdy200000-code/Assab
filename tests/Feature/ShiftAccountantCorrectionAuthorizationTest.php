<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftReportCorrectionActorPolicy;
use Modules\Shift\Services\ShiftReportMutationGuard;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftAccountantCorrectionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Branch $otherBranch;

    private Shift $shiftTemplate;

    private Cashier $cashier;

    private BranchManager $manager;

    private CashierShift $cashierShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'شركة الاختبار',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'علامة الاختبار',
            'abbr' => 'TEST',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
        ]);
    }

    public function test_active_accountant_authorized_for_branch_passes_policy_and_guard(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب الفرع',
            'email' => 'acc@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);

        $policy = app(ShiftReportCorrectionActorPolicy::class);
        $role = $policy->assertAllowed($this->cashierShift, $accountant);
        $this->assertSame('accountant', $role);

        DB::transaction(function () use ($accountant) {
            $locked = app(ShiftReportMutationGuard::class)->lockCorrection($this->cashierShift, $accountant);
            $this->assertSame($this->cashierShift->id, $locked->id);
        });
    }

    public function test_active_accountant_with_scope_all_passes_for_company_branch(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب الشركة العام',
            'email' => 'all_acc@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        $policy = app(ShiftReportCorrectionActorPolicy::class);
        $this->assertSame('accountant', $policy->assertAllowed($this->cashierShift, $accountant));
    }

    public function test_user_without_accountant_role_fails_with_403(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'موظف عادي',
            'email' => 'emp@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $user->id,
            'role_key' => 'employee',
            'scope' => 'all',
        ]);

        $policy = app(ShiftReportCorrectionActorPolicy::class);
        $this->expectException(AccessDeniedHttpException::class);
        $policy->assertAllowed($this->cashierShift, $user);
    }

    public function test_inactive_accountant_fails_with_403(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب معطل',
            'email' => 'inactive@test.com',
            'password' => 'secret',
            'status' => 'suspended',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        $policy = app(ShiftReportCorrectionActorPolicy::class);
        $this->expectException(AccessDeniedHttpException::class);
        $policy->assertAllowed($this->cashierShift, $accountant);
    }

    public function test_accountant_scoped_to_other_branch_fails_with_403(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب فرع آخر',
            'email' => 'other_branch@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'branch',
            'branch_ids' => [$this->otherBranch->id],
        ]);

        $policy = app(ShiftReportCorrectionActorPolicy::class);
        $this->expectException(AccessDeniedHttpException::class);
        $policy->assertAllowed($this->cashierShift, $accountant);
    }

    public function test_report_owner_cashier_and_assigned_manager_still_allowed(): void
    {
        $policy = app(ShiftReportCorrectionActorPolicy::class);

        $this->assertSame('cashier', $policy->assertAllowed($this->cashierShift, $this->cashier));
        $this->assertSame('branch_manager', $policy->assertAllowed($this->cashierShift, $this->manager));
    }

    public function test_pending_review_projection_allows_correction_when_workday_not_submitted(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب معتمد',
            'email' => 'acc_pending@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        // Ensure workday exists and is not submitted
        BranchManagerShift::where('branch_manager_id', $this->manager->id)->update([
            'daily_report_submitted' => false,
            'daily_report_submitted_at' => null,
        ]);

        // Ensure admin projection is pending_review
        DB::table('asab_shifts')->where('legacy_shift_id', $this->cashierShift->id)->delete();
        DB::table('asab_shifts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $this->company->id,
            'legacy_shift_id' => $this->cashierShift->id,
            'status' => 'pending_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::transaction(function () use ($accountant) {
            $locked = app(ShiftReportMutationGuard::class)->lockCorrection($this->cashierShift, $accountant);
            $this->assertSame($this->cashierShift->id, $locked->id);
        });
    }

    public function test_submitted_workday_blocks_correction_with_409(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب',
            'email' => 'acc_sub@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        BranchManagerShift::where('branch_manager_id', $this->manager->id)->update([
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        DB::transaction(function () use ($accountant) {
            app(ShiftReportMutationGuard::class)->lockCorrection($this->cashierShift, $accountant);
        });
    }

    public function test_closed_admin_projection_blocks_correction_with_409(): void
    {
        $accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'محاسب',
            'email' => 'acc_closed@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        DB::table('asab_shifts')->where('legacy_shift_id', $this->cashierShift->id)->delete();
        DB::table('asab_shifts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $this->company->id,
            'legacy_shift_id' => $this->cashierShift->id,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        DB::transaction(function () use ($accountant) {
            app(ShiftReportMutationGuard::class)->lockCorrection($this->cashierShift, $accountant);
        });
    }
}
