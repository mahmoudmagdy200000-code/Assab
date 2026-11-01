<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\ShiftCloseService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * PKG-1 / BRC-04 — Dashboard cannot close mobile-origin shifts (3 route aliases) and cannot post a
 * mobile-origin shortage without the branch-manager-approved liability allocation. Dashboard-native
 * shifts and the mobile bridge (service-level close) are unaffected.
 */
class DashboardMobileShiftCloseGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSE_ALIASES = [
        '/api/v1/accountant/shifts/%s/close',
        '/api/v1/company/me/shifts/%s/close',
        '/api/v1/company/me/branch/shifts/%s/close',
    ];

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $branchUser;

    private Employee $employee;

    private CashierShift $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'BRC04 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->user('acc@brc04.test', 'accountant', 'all');
        $this->head = $this->user('head@brc04.test', 'head', 'all');
        $this->branchUser = $this->user('brm@brc04.test', 'branch', 'branch', [$this->branch->id]);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => '2001', 'name' => 'Cashier E', 'role' => 'كاشير', 'status' => 'active']);

        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->legacy = CashierShift::create(['cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => 'not_started']);
        DB::transaction(fn () => app(ShiftReportRevisionService::class)->recordCashierRevision($this->legacy, 'cashier', $cashier->id));
        DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->update(['fresh_count_required' => false]);
    }

    private function user(string $email, string $role, string $scope, ?array $branches = null): AsabUser
    {
        $u = AsabUser::create(['company_id' => $this->company->id, 'name' => $role, 'email' => $email,
            'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => $scope, 'branch_ids' => $branches]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $u->id,
            'role_key' => $role,
            'status' => 'active',
        ]);

        return $u;
    }

    private function mirror(bool $mobile, int $sales = 10000): AdminShift
    {
        return AdminShift::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'cashier_employee_id' => $this->employee->id, 'cashier_name' => 'Cashier E',
            'shift_type' => 'day', 'started_at' => now(), 'status' => 'active',
            'legacy_shift_id' => $mobile ? $this->legacy->id : null,
            'sales_amount' => $sales, 'opening_float' => 0,
        ]);
    }

    private function actorFor(int $aliasIndex): AsabUser
    {
        return $aliasIndex === 2 ? $this->branchUser : $this->accountant;
    }

    private function snapshot(): array
    {
        return [
            'operations' => Operation::count(),
            'movements' => EmployeeMovement::count(),
            'idem' => DB::table('asab_command_idempotency_keys')->count(),
        ];
    }

    public function test_mobile_origin_shift_close_is_409_on_all_three_aliases_before_validation_with_zero_writes(): void
    {
        $mirror = $this->mirror(true);
        $before = $this->snapshot();

        foreach (self::CLOSE_ALIASES as $i => $pattern) {
            // Empty body: proves the guard runs before validation (would otherwise be 422).
            foreach ([[], ['cashActualHalalas' => 7000]] as $body) {
                $res = $this->actingAs($this->actorFor($i), 'sanctum')
                    ->withHeader('Idempotency-Key', (string) Str::uuid())
                    ->postJson(sprintf($pattern, $mirror->id), $body);
                $res->assertStatus(409)->assertJsonPath('error.code', 'MOBILE_SHIFT_CLOSES_ON_MOBILE');
            }
        }

        $this->assertSame('active', $mirror->fresh()->status);
        $this->assertSame($before['operations'], Operation::count());
        $this->assertSame($before['movements'], EmployeeMovement::count());
    }

    public function test_unknown_shift_is_404_not_409_and_native_shift_still_closes_on_every_alias(): void
    {
        foreach (self::CLOSE_ALIASES as $i => $pattern) {
            $this->actingAs($this->actorFor($i), 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson(sprintf($pattern, (string) Str::uuid()), ['cashActualHalalas' => 1])->assertStatus(404);

            $native = $this->mirror(false);
            $this->actingAs($this->actorFor($i), 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson(sprintf($pattern, $native->id), ['cashActualHalalas' => 9000])->assertOk();
            $this->assertSame('pending_review', $native->fresh()->status);
        }
        $this->assertSame(3, Operation::where('module_key', 'shifts')->count());
    }

    public function test_native_close_still_validates_the_body(): void
    {
        $native = $this->mirror(false);
        $this->actingAs($this->accountant, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(sprintf(self::CLOSE_ALIASES[1], $native->id), [])->assertStatus(422);
    }

    public function test_mobile_bridge_service_close_is_unchanged(): void
    {
        $mirror = $this->mirror(true);
        $result = app(ShiftCloseService::class)->close($mirror, ['cashActualHalalas' => 10000], $this->accountant);

        $this->assertSame('pending_review', $mirror->fresh()->status);
        $this->assertSame('shifts', $result['operation']->module_key);
    }

    /** A mobile-origin shortage with NO approved liability allocation (cash_count_state not 'counted'). */
    private function approvedMobileShortageOperation(bool $mobile): Operation
    {
        $mirror = $this->mirror($mobile);
        $op = app(ShiftCloseService::class)->close($mirror, ['cashActualHalalas' => 7000], $this->accountant)['operation'];
        $this->assertSame(-3000, (int) $mirror->fresh()->variance);
        $this->assertNull($mirror->fresh()->cash_count_state);
        $op->update(['status' => Operation::STATUS_APPROVED]);

        return $op->fresh();
    }

    public function test_final_approval_of_mobile_shortage_without_allocation_is_blocked_and_posts_nothing(): void
    {
        $op = $this->approvedMobileShortageOperation(true);

        try {
            app(OperationService::class)->finalApprove($op, $this->head);
            $this->fail('Mobile shortage was posted without an approved liability allocation.');
        } catch (AsabException $e) {
            $this->assertSame('BRANCH_LIABILITY_APPROVAL_PENDING', $e->errorCode);
        }

        $this->assertSame(Operation::STATUS_APPROVED, $op->fresh()->status, 'final state rolled back with the guard');
        $this->assertSame('pending_review', AdminShift::find($op->payload['shiftId'])->status);
        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_bulk_final_approval_rejects_mixed_mobile_selection_and_preserves_native_only_bulk(): void
    {
        $mobileOp = $this->approvedMobileShortageOperation(true);
        $nativeOp = $this->approvedMobileShortageOperation(false);

        try {
            app(OperationService::class)->bulkFinalApprove([$mobileOp->id, $nativeOp->id], $this->head);
            $this->fail('A protected mixed selection must be rejected as a whole');
        } catch (AsabException $e) {
            $this->assertSame('BULK_WORKFLOW_NOT_ALLOWED', $e->errorCode);
        }
        $this->assertSame(Operation::STATUS_APPROVED, $nativeOp->fresh()->status);
        $this->assertSame('pending_review', AdminShift::find($nativeOp->payload['shiftId'])->status);
        $this->assertSame(0, EmployeeMovement::count());
        // The verified Native-only selection remains supported, without admitting the mobile member.
        $result = app(OperationService::class)->bulkFinalApprove([$nativeOp->id], $this->head);
        $this->assertSame([$nativeOp->public_id], $result['finalApproved']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(Operation::STATUS_APPROVED, $mobileOp->fresh()->status);
        $this->assertSame('pending_review', AdminShift::find($mobileOp->payload['shiftId'])->status);
        $this->assertSame(Operation::STATUS_FINAL, $nativeOp->fresh()->status);
        $this->assertSame('closed', AdminShift::find($nativeOp->payload['shiftId'])->status);
        $this->assertSame(1, EmployeeMovement::count(), 'only the dashboard-native shortage posted its default allocation');
    }

    public function test_bulk_final_approval_http_route_reports_blocked_mobile_shortage(): void
    {
        $mobileOp = $this->approvedMobileShortageOperation(true);

        $res = $this->actingAs($this->head, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/operations/bulk-final-approve', ['operationIds' => [$mobileOp->id]]);

        $res->assertStatus(409)->assertJsonPath('error.code', 'BULK_WORKFLOW_NOT_ALLOWED');
        $this->assertSame(Operation::STATUS_APPROVED, $mobileOp->fresh()->status);
        $this->assertSame(0, EmployeeMovement::count());
    }

    public function test_native_shortage_default_posting_is_unchanged(): void
    {
        $op = $this->approvedMobileShortageOperation(false);

        app(OperationService::class)->finalApprove($op, $this->head);

        $this->assertSame('closed', AdminShift::find($op->payload['shiftId'])->status);
        $this->assertSame(1, EmployeeMovement::count());
    }
}
