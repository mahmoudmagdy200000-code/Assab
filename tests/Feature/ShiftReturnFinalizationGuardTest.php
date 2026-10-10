<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/** A stale physical count must not reach the existing final/daily pipelines. */
class ShiftReturnFinalizationGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private CashierShift $legacy;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $company = AsabCompany::create(['name' => 'Return guard', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Guard', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->legacy = CashierShift::create(['cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => 'not_started']);
        DB::transaction(fn () => app(ShiftReportRevisionService::class)->recordCashierRevision($this->legacy, 'cashier', $cashier->id));
        DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->update(['fresh_count_required' => true]);
        $this->accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Accountant',
            'email' => 'return-guard@test.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
    }

    public function test_admin_close_cannot_bypass_a_required_physical_recount(): void
    {
        $mirror = AdminShift::create(['company_id' => $this->accountant->company_id, 'branch_id' => $this->branch->id,
            'cashier_name' => 'Cashier', 'shift_type' => 'day', 'started_at' => now(), 'status' => 'active',
            'legacy_shift_id' => $this->legacy->id, 'sales_amount' => 10000, 'opening_float' => 0]);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/shifts/{$mirror->id}/close", [
            'cashActualHalalas' => 10000,
        ])->assertStatus(409)->assertJsonPath('error.code', 'PHYSICAL_RECOUNT_REQUIRED');
        $this->assertSame('active', $mirror->fresh()->status);
        $this->assertSame(0, Operation::where('module_key', 'shifts')->count());
    }

    public function test_daily_submission_cannot_sweep_a_report_requiring_fresh_count(): void
    {
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->firstOrFail();
        $workday->update(['status' => 'completed', 'daily_report_submitted' => false]);
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', [])
            ->assertStatus(409)->assertJsonPath('code', 'PHYSICAL_RECOUNT_REQUIRED');
        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
        $this->assertTrue((bool) DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->value('fresh_count_required'));
    }

    public function test_final_approval_cannot_close_a_projection_with_stale_count_evidence(): void
    {
        DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->update(['fresh_count_required' => false]);
        $mirror = AdminShift::create(['company_id' => $this->accountant->company_id, 'branch_id' => $this->branch->id,
            'cashier_name' => 'Cashier', 'shift_type' => 'day', 'started_at' => now(), 'status' => 'active',
            'legacy_shift_id' => $this->legacy->id, 'sales_amount' => 10000, 'opening_float' => 0]);
        $result = app(\Modules\Admin\Services\ShiftCloseService::class)->close($mirror, ['cashActualHalalas' => 10000], $this->accountant);
        $operation = $result['operation'];
        $operation->update(['status' => Operation::STATUS_FINAL]);
        DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->update(['fresh_count_required' => true]);
        try {
            app(\Modules\Admin\Services\ShiftCloseService::class)->onFinalApproved($operation, $this->accountant);
            $this->fail('Stale physical count reached final approval.');
        } catch (\Modules\Admin\Exceptions\AsabException $error) {
            $this->assertSame('PHYSICAL_RECOUNT_REQUIRED', $error->errorCode);
        }
        $this->assertSame('pending_review', $mirror->fresh()->status);
        $this->assertSame(0, \Modules\Admin\Models\EmployeeMovement::count());
    }

    public function test_daily_submission_checks_carried_reports_older_than_the_recent_window(): void
    {
        $oldDate = today()->subDays(10);
        DB::table('cashier_shifts')->where('id', $this->legacy->id)->update(['shift_date' => $oldDate->toDateString()]);
        \Modules\Shift\Models\CashierShiftHandover::create([
            'cashier_shift_id' => $this->legacy->id, 'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id, 'handover_amount' => '0.00', 'variance_amount' => '0.00',
            'handover_date' => $oldDate, 'handover_time' => $oldDate, 'status' => 'approved', 'daily_closed_at' => null,
        ]);
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->firstOrFail();
        $workday->update(['status' => 'completed', 'daily_report_submitted' => false]);
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', [])
            ->assertStatus(409)->assertJsonPath('code', 'PHYSICAL_RECOUNT_REQUIRED');
        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
        $this->assertDatabaseHas('cashier_shift_handovers', ['cashier_shift_id' => $this->legacy->id, 'daily_closed_at' => null]);
    }
}
