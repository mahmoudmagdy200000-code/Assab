<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftReportReviewService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

trait Pkg6ReviewFixture
{
    protected Operation $operation;
    protected CashierShift $source;
    protected BranchManagerShift $day;
    protected AsabUser $accountant;
    protected AsabUser $head;
    protected ShiftLiabilityDailyLock $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $company = AsabCompany::create(['name' => 'PKG6', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'PKG6', 'status' => 'active', 'sub_status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $manager = BranchManager::where('branch_id', $branch->id)->first() ?? BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $this->source = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'total_sales' => '100.00', 'card_payments' => '0.00', 'cash_collected' => '100.00',
        ]));
        $this->day = BranchManagerShift::where('branch_id', $branch->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today(), 'status' => 'completed']);
        DB::table('branch_manager_shifts')->where('id', $this->day->id)->update(['daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);
        $this->day->refresh();
        $admin = AdminShift::create(['company_id' => $company->id, 'branch_id' => $branch->id,
            'legacy_shift_id' => $this->source->id, 'cashier_name' => 'PKG6 Cashier', 'shift_type' => 'test',
            'status' => 'pending_review', 'sales_amount' => 10000, 'cash_count_state' => 'counted',
            'cash_actual' => 10000, 'cash_expected' => 10000, 'variance' => 0]);
        $this->operation = Operation::create(['public_id' => 'SHF-'.Str::uuid(), 'company_id' => $company->id,
            'branch_id' => $branch->id, 'module_key' => 'shifts', 'origin' => 'mobile', 'status' => 'pending',
            'payload' => ['shiftId' => $admin->id, 'legacyShiftId' => $this->source->id,
                'salesHalalas' => 10000, 'cardTotalHalalas' => 0, 'aggregatorTotalsHalalas' => 0,
                'cashActualHalalas' => 10000, 'cashExpectedHalalas' => 10000, 'varianceHalalas' => 0], 'operation_date' => now()]);
        $this->accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Accountant',
            'email' => 'pkg6@test.invalid', 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $this->head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head',
            'email' => 'pkg6-head@test.invalid', 'password' => 'test', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->source, 'cashier', $cashier->id);
        $count = app(ShiftCashCountService::class)->record($this->source, $revision, 10000, 0, 0, 10000);
        $this->membership = ShiftLiabilityDailyLock::create(['cashier_shift_id' => $this->source->id,
            'branch_manager_shift_id' => $this->day->id, 'report_revision' => $count->evidence_revision_id,
            'locked_by_id' => $manager->id, 'locked_at' => now()]);
    }

}

class Pkg6ApprovalStageGuardTest extends TestCase
{
    use RefreshDatabase, Pkg6ReviewFixture;

    public function test_accountant_approval_captures_current_member_coordinate(): void
    {
        $result = app(OperationService::class)->approve($this->operation, $this->accountant);
        $this->assertSame('approved', $result->status);
        $this->assertSame($this->source->id, $result->payload['pkg6Review']['approved_snapshot']['source_id']);
        $this->assertSame($this->day->id, $result->payload['pkg6Review']['approved_snapshot']['day_id']);
        $this->assertSame('head_review', $result->payload['pkg6Review']['phase']);
    }

    public function test_unsubmitted_day_cannot_reach_head_queue_and_writes_no_step(): void
    {
        DB::table('branch_manager_shifts')->where('id', $this->day->id)->update(['daily_report_submitted' => false]);
        $this->refused('DAY_REPORT_NOT_SUBMITTED');
    }

    public function test_stale_daily_membership_evidence_is_refused(): void
    {
        $this->membership->update(['report_revision' => (string) Str::uuid()]);
        $this->refused('STALE_DAY_MEMBER_EVIDENCE');
    }

    public function test_fresh_count_requirement_cannot_be_bypassed_by_approval(): void
    {
        DB::table('shift_report_aggregates')->where('source_id', $this->source->id)->update(['fresh_count_required' => true]);
        $this->refused('PHYSICAL_RECOUNT_REQUIRED');
    }

    public function test_ambiguous_original_operation_is_refused(): void
    {
        $copy = $this->operation->replicate();
        $copy->public_id = 'SHF-'.Str::uuid();
        $copy->save();
        $this->refused('REPORT_OPERATION_MAPPING_REQUIRED');
    }

    public function test_head_cannot_finalize_a_changed_approved_snapshot(): void
    {
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        $payload = $approved->payload;
        $payload['pkg6Review']['approved_snapshot']['count_id'] = (string) Str::uuid();
        $approved->update(['payload' => $payload]);
        try {
            app(OperationService::class)->finalApprove($approved, $this->head);
            $this->fail('Stale head approval succeeded.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('STALE_APPROVED_REPORT', $error->getMessage());
        }
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame(0, $approved->steps()->where('stage_id', 'final')->count());
    }

    public function test_head_return_marker_requires_manager_return_cycle(): void
    {
        $payload = $this->operation->payload;
        $payload['pkg6Review']['phase'] = 'head_returned';
        $this->operation->update(['payload' => $payload]);
        $this->refused('REPORT_RETURN_CYCLE_REQUIRED');
    }

    public function test_stale_shf_financial_snapshot_is_refused(): void
    {
        $payload = $this->operation->payload;
        $payload['cashActualHalalas'] = 9999;
        $this->operation->update(['payload' => $payload]);
        $this->refused('STALE_SHF_REPORT_SNAPSHOT');
    }

    public function test_report_in_head_review_cannot_be_edited(): void
    {
        app(OperationService::class)->approve($this->operation, $this->accountant);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('REPORT_IN_HEAD_REVIEW');
        DB::transaction(function () {
            app(ShiftReportReviewService::class)->lockCashier($this->source);
            app(ShiftReportReviewService::class)->assertReportEditable($this->source);
        });
    }

    public function test_root_locks_preserve_original_membership_and_report_identity(): void
    {
        DB::transaction(function () {
            $locked = app(ShiftReportReviewService::class)->lockOperation($this->operation);
            $this->assertSame($this->operation->id, $locked->id);
            $this->assertSame($this->source->id, $locked->payload['legacyShiftId']);
        });
        $this->assertSame($this->day->id, $this->membership->fresh()->branch_manager_shift_id);
        $this->assertNull($this->membership->fresh()->released_at);
        $this->assertNull($this->membership->fresh()->superseded_at);
    }

    private function refused(string $code): void
    {
        try {
            app(OperationService::class)->approve($this->operation, $this->accountant);
            $this->fail('Invalid approval succeeded.');
        } catch (ConflictHttpException $error) {
            $this->assertSame($code, $error->getMessage());
        }
        $this->assertSame('pending', $this->operation->fresh()->status);
        $this->assertSame(0, $this->operation->steps()->count());
    }
}
