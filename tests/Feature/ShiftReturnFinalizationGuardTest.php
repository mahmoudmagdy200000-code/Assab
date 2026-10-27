<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShortagePostingService;
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
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
    }

    public function test_admin_close_cannot_bypass_a_required_physical_recount(): void
    {
        $mirror = AdminShift::create(['company_id' => $this->accountant->company_id, 'branch_id' => $this->branch->id,
            'cashier_name' => 'Cashier', 'shift_type' => 'day', 'started_at' => now(), 'status' => 'active',
            'legacy_shift_id' => $this->legacy->id, 'sales_amount' => 10000, 'opening_float' => 0]);
        $this->actingAs($this->accountant, 'sanctum')->postJson("/api/v1/accountant/shifts/{$mirror->id}/close", [
            'cashActualHalalas' => 10000,
        ])->assertStatus(409)->assertJsonPath('error.code', 'MOBILE_SHIFT_CLOSES_ON_MOBILE');
        $this->assertSame('active', $mirror->fresh()->status);
        $this->assertSame(0, Operation::where('module_key', 'shifts')->count());
    }

    public function test_daily_submission_cannot_sweep_a_report_requiring_fresh_count(): void
    {
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->firstOrFail();
        $workday->update(['status' => 'completed', 'daily_report_submitted' => false]);
        app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 0);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
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

    public function test_final_approval_uses_exact_legacy_shift_id_not_a_matching_template_id(): void
    {
        $mirror = AdminShift::create([
            'company_id' => $this->accountant->company_id,
            'branch_id' => $this->branch->id,
            'cashier_name' => 'Cashier',
            'shift_type' => 'day',
            'started_at' => now(),
            'status' => 'active',
            'legacy_shift_id' => $this->legacy->id,
            'sales_amount' => 1000,
            'opening_float' => 0,
        ]);
        DB::table('shift_report_aggregates')->where('source_id', $this->legacy->id)->update(['fresh_count_required' => false]);

        // A decoy has the admin projection ID as its shift-template foreign key.
        // The old lookup could select this row by shift_id/date.
        $decoyStart = null;
        for ($hour = 0; $hour < 24; $hour++) {
            $candidateStart = sprintf('%02d:00:00', $hour);
            $candidateEnd = sprintf('%02d:00:00', ($hour + 1) % 24);
            if (! Shift::where('branch_id', $this->branch->id)
                ->where('start_time', $candidateStart)->where('end_time', $candidateEnd)->exists()) {
                $decoyStart = [$candidateStart, $candidateEnd];
                break;
            }
        }
        $this->assertNotNull($decoyStart, 'A unique decoy shift time must be available.');
        Shift::factory()->create([
            'id' => $mirror->id,
            'branch_id' => $this->branch->id,
            'name' => 'Decoy template',
            'start_time' => $decoyStart[0],
            'end_time' => $decoyStart[1],
        ]);
        $decoy = CashierShift::create([
            'cashier_id' => $this->legacy->cashier_id,
            'shift_id' => $mirror->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        // Prepare a completed, genuinely counted report through the mobile path.
        // The decoy must never supply evidence for the exact legacy source.
        $cashier = $this->legacy->cashier;
        app(CashierShiftStartService::class)->startShift($this->legacy);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->legacy->id}/end", [
            'total_sales' => '10.00',
            'cash_collected' => '10.00',
            'card_payments' => '0.00',
            'counted_cash' => '0.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $cashier->id, 'amount' => '10.00'],
            ],
        ], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertOk();

        $count = app(ShiftCashCountService::class)->currentFor($this->legacy->id);
        $this->assertNotNull($count);
        $this->assertSame(-1000, $count->variance_halalas);
        $this->assertNull(app(ShiftCashCountService::class)->currentFor($decoy->id));
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->legacy->id)
            ->whereNull('superseded_at')->sole();
        $this->assertSame($count->evidence_revision_id, $allocation->report_revision);
        $this->assertSame(1000, (int) $allocation->shares()->sum('amount_halalas'));
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($this->legacy->fresh());
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->legacy->id}/liability/approve", [
            'version' => $allocation->version,
            'expected_revision' => $revision->revision_number,
        ], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $allocation->refresh();
        $this->assertSame('approved', $allocation->manager_approval_status);
        $this->assertNotNull($allocation->manager_approved_at);

        $service = app(\Modules\Admin\Services\ShiftCloseService::class);
        $closed = $service->close($mirror, ['cashActualHalalas' => 0], $this->accountant);
        $operation = $closed['operation'];
        $operation->update(['status' => Operation::STATUS_FINAL]);
        $mirror->update(['cash_count_state' => 'counted']);

        // Observe exact source selection, while executing the real reconciler.
        // Its expected revision is numeric; allocation evidence is a UUID.
        $realPosting = app(ShortagePostingService::class);
        $beforePostings = DB::table('shift_liability_postings')->orderBy('id')->get()->all();
        $posting = \Mockery::mock(ShortagePostingService::class);
        $posting->shouldReceive('reconcile')->once()->withArgs(function ($shift, $actor, $expectedRevision, $version) use ($allocation, $decoy, $revision) {
            return $shift->is($this->legacy)
                && ! $shift->is($decoy)
                && $expectedRevision === $revision->revision_number
                && $version === $allocation->version;
        })->andReturnUsing(fn (...$args) => $realPosting->reconcile(...$args));
        app()->instance(ShortagePostingService::class, $posting);

        $service->onFinalApproved($operation, $this->accountant);

        $this->assertSame('closed', $mirror->fresh()->status);
        $this->assertEquals($beforePostings, DB::table('shift_liability_postings')->orderBy('id')->get()->all());
        $this->assertSame(1000, (int) DB::table('shift_liability_postings')->where('cashier_shift_id', $this->legacy->id)->sum('delta_halalas'));
        $this->assertSame(0, DB::table('shift_liability_postings')->where('cashier_shift_id', $decoy->id)->count());
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
        app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 0);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)->assertJsonPath('code', 'PHYSICAL_RECOUNT_REQUIRED');
        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
        $this->assertDatabaseHas('cashier_shift_handovers', ['cashier_shift_id' => $this->legacy->id, 'daily_closed_at' => null]);
    }
}
