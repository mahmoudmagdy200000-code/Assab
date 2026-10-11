<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftReportReopenService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftReportReopenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Shift $template;

    private CashierShift $cashierShift;

    private ShiftReportReopenService $reopenService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->template = Shift::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
        ]);

        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        $this->reopenService = app(ShiftReportReopenService::class);
    }

    public function test_submitted_workday_blocks_cashier_report_reopen_with_409(): void
    {
        // Workday submitted by manager
        $managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first();
        if ($managerShift) {
            $managerShift->update([
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
            ]);
        } else {
            BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
            ]);
        }

        $this->expectException(ConflictHttpException::class);
        $this->reopenService->reopenCashierReport(
            $this->cashierShift,
            $this->cashier,
            expectedRevision: 1,
            reason: 'Correction needed after daily close',
            operationId: (string) Str::uuid()
        );
    }

    public function test_daily_closed_handover_blocks_reopen_with_409(): void
    {
        CashierShiftHandover::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'handover_date' => today(),
            'handover_time' => now(),
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '100.00',
            'status' => 'approved',
            'daily_closed_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->reopenService->reopenCashierReport(
            $this->cashierShift,
            $this->cashier,
            expectedRevision: 1,
            reason: 'Correction after close',
            operationId: (string) Str::uuid()
        );
    }

    public function test_active_daily_lock_blocks_reopen_with_409(): void
    {
        $managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first();
        if (! $managerShift) {
            $managerShift = BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        }

        DB::table('shift_liability_daily_locks')->insert([
            'id' => (string) Str::uuid(),
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $managerShift->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->reopenService->reopenCashierReport(
            $this->cashierShift,
            $this->cashier,
            expectedRevision: 1,
            reason: 'Correction during active lock',
            operationId: (string) Str::uuid()
        );
    }

    public function test_legacy_daily_close_reopen_http_endpoint_guarded_by_d_pr(): void
    {
        $managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first();
        if ($managerShift) {
            $managerShift->update([
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
                'can_reopen' => true,
            ]);
        } else {
            $managerShift = BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now(),
                'can_reopen' => true,
            ]);
        }

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/branch-manager/workday/daily-close/reopen', [
                'reopen_reason' => 'Manager trying to reopen closed day',
            ]);

        // D-PR boundary policy: must be blocked with 409 REPORT_REOPEN_REQUIRED
        $response->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');
        $this->assertTrue($managerShift->fresh()->daily_report_submitted, 'Daily report must remain submitted');
    }

    public function test_cross_branch_manager_cannot_reopen_report(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->reopenService->reopenCashierReport(
            $this->cashierShift,
            $otherManager,
            expectedRevision: 1,
            reason: 'Cross branch reopen',
            operationId: (string) Str::uuid()
        );
    }
}
