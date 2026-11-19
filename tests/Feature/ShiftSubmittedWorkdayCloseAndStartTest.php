<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityShare;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftSubmittedWorkdayCloseAndStartTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Shift $dayTemplate;

    private Shift $overnightTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep wall clock well inside the 08:00-16:00 start window on 2026-11-15 Asia/Riyadh.
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Riyadh'));

        $this->company = AsabCompany::create(['name' => 'QA Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'QA Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'QA Handover Branch',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $this->dayTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);
        $this->overnightTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'start_time' => '23:00:00',
            'end_time' => '02:00:00',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createWorkday(Branch $branch, BranchManager $manager, string $date, bool $submitted = false, ?Carbon $submittedAt = null): BranchManagerShift
    {
        return BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => $date,
            'status' => $submitted || $submittedAt !== null ? 'completed' : 'in_progress',
            'daily_report_submitted' => $submitted,
            'daily_report_submitted_at' => $submitted ? ($submittedAt ?? now()) : $submittedAt,
        ]);
    }

    private function createPendingShift(Cashier $cashier, Shift $template, string $date): CashierShift
    {
        return CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => $date,
            'status' => ShiftStatus::NOT_STARTED,
            'actual_start_time' => null,
            'operational_chain_id' => null,
        ]);
    }

    private function createActiveShift(Cashier $cashier, Shift $template, string $date): CashierShift
    {
        $shift = $this->createPendingShift($cashier, $template, $date);
        $shift->update([
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(2),
        ]);

        return $shift->fresh();
    }

    private function shortagePayload(BranchManager $responsible, ?string $reason = null): array
    {
        $payload = [
            'total_sales' => '500.00',
            'card_payments' => '300.00',
            'cash_collected' => '200.00',
            'counted_cash' => '195.00',
            'shortage_allocations' => [
                [
                    'responsible_type' => 'branch_manager',
                    'responsible_id' => $responsible->id,
                    'amount' => '5.00',
                ],
            ],
        ];

        if ($reason !== null) {
            $payload['allocation_reason'] = $reason;
        }

        return $payload;
    }

    private function previewPayload(): array
    {
        return [
            'total_sales' => '500.00',
            'card_payments' => '300.00',
            'counted_cash' => '195.00',
        ];
    }

    private function snapshotTables(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_acceptance_1_open_workday_allows_both_starts_and_submitted_workday_blocks_both_without_writes(): void
    {
        $workday = $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: false);
        $cashierShift = $this->createPendingShift($this->cashierA, $this->dayTemplate, '2026-11-15');
        $managerStartedShift = $this->createPendingShift($this->cashierB, $this->dayTemplate, '2026-11-15');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$cashierShift->id}/start")
            ->assertOk();

        $this->assertSame(ShiftStatus::IN_PROGRESS, $cashierShift->fresh()->status);
        $this->assertNotNull($cashierShift->fresh()->actual_start_time);
        $this->assertSame(1, $cashierShift->history()->where('action', 'started')->count());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$managerStartedShift->id}")
            ->assertOk();

        $this->assertSame(ShiftStatus::IN_PROGRESS, $managerStartedShift->fresh()->status);
        $this->assertNotNull($managerStartedShift->fresh()->actual_start_time);
        $this->assertSame(1, $managerStartedShift->history()->where('action', 'started')->count());

        // Submitted workday must reject both pending starts cleanly before mutating any state.
        $cashierC = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $blockedByCashier = $this->createPendingShift($cashierC, $this->dayTemplate, '2026-11-15');
        $cashierD = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $blockedByManager = $this->createPendingShift($cashierD, $this->dayTemplate, '2026-11-15');

        $workday->update([
            'status' => 'completed',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $before = $this->snapshotTables(['cashier_shifts', 'cashier_shift_history', 'branch_manager_shifts']);

        $this->actingAs($cashierC, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$blockedByCashier->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$blockedByManager->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        // Fallback start-by-manager by cashierId must fail identically.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$cashierD->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->assertSame($before, $this->snapshotTables(['cashier_shifts', 'cashier_shift_history', 'branch_manager_shifts']));

        foreach ([$blockedByCashier, $blockedByManager] as $shift) {
            $fresh = $shift->fresh();
            $this->assertNull($fresh->actual_start_time);
            $this->assertNull($fresh->operational_chain_id);
            $this->assertSame(ShiftStatus::NOT_STARTED, $fresh->status);
            $this->assertSame(0, $fresh->history()->where('action', 'started')->count());
        }
    }

    public function test_submitted_workday_blocks_legacy_shared_reassignment_before_separation_or_writes(): void
    {
        $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: true, submittedAt: now());
        $source = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->dayTemplate->id,
            'shift_date' => '2026-11-15',
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
        ]);
        $source->update([
            'original_cashier_id' => $this->cashierA->id,
            'cashier_id' => $this->cashierB->id,
            'status' => ShiftStatus::REASSIGNED,
        ]);
        $pendingLegacy = app(HandoverService::class)->acceptReassignedShift($source, $this->cashierB->id);

        $before = $this->snapshotTables(['cashier_shifts', 'cashier_shift_history']);

        try {
            app(CashierShiftStartService::class)->startShift($pendingLegacy);
            $this->fail('Submitted workday must block legacy reassignment start before separating rows.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('REPORT_REOPEN_REQUIRED', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshotTables(['cashier_shifts', 'cashier_shift_history']));
    }

    public function test_acceptance_2_active_shift_on_submitted_day_fails_preview_and_final_save_with_409_and_no_writes(): void
    {
        $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: true, submittedAt: now());
        $activeShift = $this->createActiveShift($this->cashierA, $this->dayTemplate, '2026-11-15');

        $auditedTables = [
            'cashier_shifts',
            'shift_report_revisions',
            'shift_report_cash_counts',
            'shift_liability_allocations',
            'shift_liability_shares',
            'cashier_shift_handovers',
            'cashier_shift_handover_receipts',
            'personal_ledger_transactions',
            'cashier_custody_transactions',
            'cashier_shift_history',
        ];
        $before = $this->snapshotTables($auditedTables);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$activeShift->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->assertSame($before, $this->snapshotTables($auditedTables));

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/end", $this->shortagePayload($this->manager, 'QA shortage allocation'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$activeShift->id}/end", $this->shortagePayload($this->manager, 'Cashier close attempt'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->assertSame($before, $this->snapshotTables($auditedTables));
        $this->assertSame(ShiftStatus::IN_PROGRESS, $activeShift->fresh()->status);
    }

    public function test_acceptance_3_manager_end_shift_only_requires_reason_and_succeeds_once_without_handover(): void
    {
        $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: false);
        $activeShift = $this->createActiveShift($this->cashierA, $this->dayTemplate, '2026-11-15');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertOk()
            ->assertJsonPath('data.allocation_required', true)
            ->assertJsonPath('data.shortage_to_allocate', 5);

        // Missing allocation reason must return 422 without persisting any close artifacts.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/end", $this->shortagePayload($this->manager))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(ShiftStatus::IN_PROGRESS, $activeShift->fresh()->status);
        $this->assertSame(0, ShiftReportCashCount::count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
        $this->assertSame(0, CashierShiftHandover::count());

        $reason = 'Manager verified SAR 5 register shortage at close';
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/end", $this->shortagePayload($this->manager, $reason))
            ->assertOk()
            ->assertJsonPath('success', true);

        $freshShift = $activeShift->fresh();
        $this->assertSame(ShiftStatus::COMPLETED, $freshShift->status);
        $this->assertNotNull($freshShift->actual_end_time);

        $this->assertSame(1, ShiftReportRevision::count());
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($freshShift);
        $this->assertNotNull($revision);
        $this->assertSame(1, $revision->revision_number);

        $this->assertSame(1, ShiftReportCashCount::count());
        $count = ShiftReportCashCount::where('cashier_shift_id', $activeShift->id)->sole();
        $this->assertSame(50000, $count->gross_halalas);
        $this->assertSame(30000, $count->cards_halalas);
        $this->assertSame(20000, $count->expected_halalas);
        $this->assertSame(19500, $count->counted_halalas);
        $this->assertSame(-500, $count->variance_halalas);

        $this->assertSame(1, ShiftLiabilityAllocation::count());
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $activeShift->id)->sole();
        $this->assertSame(-500, $allocation->variance_halalas);
        $this->assertSame($reason, $allocation->reason);
        $this->assertSame('branch_manager', $allocation->created_by_type);
        $this->assertSame($this->manager->id, $allocation->created_by_id);

        $share = ShiftLiabilityShare::where('allocation_id', $allocation->id)->sole();
        $this->assertSame('branch_manager', $share->responsible_type);
        $this->assertSame($this->manager->id, $share->responsible_id);
        $this->assertSame(500, $share->amount_halalas);

        $this->assertSame(0, CashierShiftHandover::count());
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_acceptance_4a_overnight_business_date_governs_start_preview_and_end(): void
    {
        // Current time is past midnight (2026-11-16 00:30), while stored shift_date is 2026-11-15.
        Carbon::setTestNow(Carbon::parse('2026-11-16 00:30:00', 'Asia/Riyadh'));

        $businessDay = $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: true, submittedAt: now()->subHours(2));
        $nextDayManager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false, 'status' => 'inactive']);
        // Keep a separate workday row for calendar 2026-11-16 that is OPEN.
        $calendarNextDay = BranchManagerShift::create([
            'branch_manager_id' => $nextDayManager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => '2026-11-16',
            'status' => 'in_progress',
            'daily_report_submitted' => false,
            'daily_report_submitted_at' => null,
        ]);

        $overnightPending = $this->createPendingShift($this->cashierA, $this->overnightTemplate, '2026-11-15');
        $overnightActive = $this->createActiveShift($this->cashierB, $this->overnightTemplate, '2026-11-15');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$overnightPending->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$overnightPending->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$overnightActive->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$overnightActive->id}/end", $this->shortagePayload($this->manager, 'Overnight closed day'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        // Flip: business date 2026-11-15 is OPEN while next calendar date 2026-11-16 is SUBMITTED.
        $businessDay->update(['status' => 'in_progress', 'daily_report_submitted' => false, 'daily_report_submitted_at' => null]);
        $calendarNextDay->update(['status' => 'completed', 'daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$overnightPending->id}/start")
            ->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $overnightPending->fresh()->status);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$overnightActive->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$overnightActive->id}/end", $this->shortagePayload($this->manager, 'Overnight open day close'))
            ->assertOk();
        $this->assertSame(ShiftStatus::COMPLETED, $overnightActive->fresh()->status);
    }

    public function test_acceptance_4b_same_date_isolation_between_branches(): void
    {
        $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: true, submittedAt: now());

        $branchB = Branch::factory()->create([
            'name' => 'Branch B',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $managerB = BranchManager::factory()->create(['branch_id' => $branchB->id, 'is_active' => true, 'status' => 'active']);
        $cashierB1 = Cashier::factory()->create(['branch_id' => $branchB->id, 'created_by' => $managerB->id]);
        $cashierB2 = Cashier::factory()->create(['branch_id' => $branchB->id, 'created_by' => $managerB->id]);
        $templateB = Shift::factory()->create(['branch_id' => $branchB->id, 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'is_active' => true]);
        $this->createWorkday($branchB, $managerB, '2026-11-15', submitted: false);

        $branchAPending = $this->createPendingShift($this->cashierA, $this->dayTemplate, '2026-11-15');
        $branchAActive = $this->createActiveShift($this->cashierB, $this->dayTemplate, '2026-11-15');

        $branchBPending = $this->createPendingShift($cashierB1, $templateB, '2026-11-15');
        $branchBActive = $this->createActiveShift($cashierB2, $templateB, '2026-11-15');

        // Branch A is blocked on 2026-11-15.
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$branchAPending->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$branchAActive->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        // Branch B on the exact same date 2026-11-15 succeeds across start, preview, and end-only.
        $this->actingAs($cashierB1, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$branchBPending->id}/start")
            ->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $branchBPending->fresh()->status);

        $this->actingAs($managerB, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$branchBActive->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertOk();

        $this->actingAs($managerB, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$branchBActive->id}/end", $this->shortagePayload($managerB, 'Branch B open day shortage'))
            ->assertOk();
        $this->assertSame(ShiftStatus::COMPLETED, $branchBActive->fresh()->status);
    }

    public function test_acceptance_4c_timestamp_only_submission_marker_blocks_start_preview_and_end(): void
    {
        // daily_report_submitted is false while daily_report_submitted_at is non-null.
        $this->createWorkday($this->branch, $this->manager, '2026-11-15', submitted: false, submittedAt: now()->subMinutes(15));

        $pendingCashier = $this->createPendingShift($this->cashierA, $this->dayTemplate, '2026-11-15');
        $pendingManager = $this->createPendingShift($this->cashierB, $this->dayTemplate, '2026-11-15');
        $cashierE = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $activeShift = $this->createActiveShift($cashierE, $this->dayTemplate, '2026-11-15');

        $before = $this->snapshotTables([
            'cashier_shifts',
            'cashier_shift_history',
            'shift_report_revisions',
            'shift_report_cash_counts',
            'shift_liability_allocations',
        ]);

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$pendingCashier->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$pendingManager->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($cashierE, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$activeShift->id}/cash-reconciliation/preview", $this->previewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$activeShift->id}/end", $this->shortagePayload($this->manager, 'Timestamp marker block'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->assertSame($before, $this->snapshotTables([
            'cashier_shifts',
            'cashier_shift_history',
            'shift_report_revisions',
            'shift_report_cash_counts',
            'shift_liability_allocations',
        ]));
    }
}
