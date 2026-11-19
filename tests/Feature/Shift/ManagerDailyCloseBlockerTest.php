<?php

namespace Tests\Feature\Shift;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ManagerDailyCloseBlockerTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Shift $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Blocker Fix Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Blocker Fix Brand',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->template = Shift::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_submit_daily_report_returns_409_code_when_already_submitted_and_404_for_missing_or_foreign_workday(): void
    {
        $workday = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);
        app(ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 0);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_REPORT_ALREADY_SUBMITTED');

        // Foreign manager's workday returns 404 DAILY_CLOSE_WORKDAY_NOT_FOUND, never 500
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $otherManager = BranchManager::factory()->create([
            'branch_id' => $otherBranch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $foreignWorkday = BranchManagerShift::create([
            'branch_manager_id' => $otherManager->id,
            'branch_id' => $otherBranch->id,
            'shift_date' => today()->subDay(),
            'status' => 'completed',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $foreignWorkday->id,
                'expected_revision' => 1,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'DAILY_CLOSE_WORKDAY_NOT_FOUND');

        // Non-existent UUID returns 404 DAILY_CLOSE_WORKDAY_NOT_FOUND, never 500
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => (string) Str::uuid(),
                'expected_revision' => 1,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'DAILY_CLOSE_WORKDAY_NOT_FOUND');
    }

    public function test_stale_revision_releases_idempotency_reservation_and_replay_after_success_does_not_duplicate_locks(): void
    {
        $workday = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);
        app(ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 0);
        app(ShiftReportRevisionService::class)->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 1);

        $key = (string) Str::uuid();
        $stalePayload = [
            'branch_manager_shift_id' => $workday->id,
            'expected_revision' => 1,
        ];

        // 1. Stale revision fails cleanly with 409 STALE_REPORT_REVISION and zero side effects
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', $stalePayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'STALE_REPORT_REVISION');

        $this->assertFalse((bool) $workday->fresh()->daily_report_submitted);
        $this->assertSame(0, ShiftLiabilityDailyLock::count());

        // 2. Reservation was released: reusing the exact same key + payload re-evaluates against current state (still 409)
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', $stalePayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'STALE_REPORT_REVISION');

        // 3. Reusing the exact same key with corrected revision 2 succeeds (no sticky poison) and replays cleanly
        $validPayload = [
            'branch_manager_shift_id' => $workday->id,
            'expected_revision' => 2,
        ];
        $first = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', $validPayload)
            ->assertOk();

        $replay = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', $validPayload)
            ->assertOk();

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
    }

    public function test_historical_workdays_can_be_closed_sequentially_by_exact_id_and_history_exposes_submission_status(): void
    {
        $day1 = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->subDays(3),
            'status' => 'completed',
        ]);
        app(ShiftReportRevisionService::class)->recordManagerRevision($day1, 'branch_manager', $this->manager->id, 0);

        $day2 = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->subDay(),
            'status' => 'completed',
        ]);
        app(ShiftReportRevisionService::class)->recordManagerRevision($day2, 'branch_manager', $this->manager->id, 0);

        // History returns daily_close.is_submitted = false for both initially
        $beforeHistory = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
            ->assertOk()
            ->json('data');

        $byIdBefore = collect($beforeHistory)->keyBy('id');
        $this->assertFalse($byIdBefore[$day1->id]['daily_close']['is_submitted']);
        $this->assertFalse($byIdBefore[$day2->id]['daily_close']['is_submitted']);

        // Close day1 by ID
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $day1->id,
                'expected_revision' => 1,
            ])
            ->assertOk();

        // Close day2 by ID
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $day2->id,
                'expected_revision' => 1,
            ])
            ->assertOk();

        $afterHistory = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/workday/history?per_page=10')
            ->assertOk()
            ->json('data');

        $byIdAfter = collect($afterHistory)->keyBy('id');
        $this->assertTrue($byIdAfter[$day1->id]['daily_close']['is_submitted']);
        $this->assertNotNull($byIdAfter[$day1->id]['daily_close']['submitted_at']);
        $this->assertTrue($byIdAfter[$day2->id]['daily_close']['is_submitted']);
    }

    public function test_final_handover_custody_reconciliation_does_not_fabricate_640_variance_on_1000_gross_378_cash_360_handover(): void
    {
        $workday = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->subDay(),
            'status' => 'completed',
            'total_sales' => '1000.00',
            'net_sales' => '869.57',
            'vat_amount' => '130.43',
            'cash_collected' => '378.00',
            'card_payments' => '600.00',
            'aggregator_payments' => '22.00',
            'handover_amount' => '360.00',
            'closing_balance' => '360.00',
            'opening_balance' => '0.00',
            'handover_status' => 'pending',
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/workday/final-handover/{$workday->id}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(1000.00, $res['financial_summary']['total_sales']);
        $this->assertEquals(378.00, $res['financial_summary']['cash_collected']);
        $this->assertEquals(360.00, $res['handover']['handover_amount']);
        $this->assertEquals(378.00, $res['handover']['expected_balance']);
        $this->assertEquals(18.00, $res['handover']['variance']);
        $this->assertNotEquals(640.00, $res['handover']['variance']);
        $this->assertSame('cash_custody_evidence', $res['handover']['custody_reconciliation']['basis']);
        $this->assertEquals(18.00, $res['handover']['custody_reconciliation']['cash_variance']);
        $this->assertSame('pending', $res['handover']['status']);
    }

    public function test_manager_covering_own_shift_closes_with_handover_and_shortage_directly_without_approve_step(): void
    {
        // Ensure mirror Cashier record exists with the manager's exact UUID
        $managerCashier = app(CashierService::class)->ensureBranchManagerCashier($this->manager->id, $this->branch->id, $this->manager);
        $this->assertNotNull($managerCashier);
        $this->assertSame((string) $this->manager->id, (string) $managerCashier->id);

        $shift = CashierShift::create([
            'cashier_id' => $managerCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        app(CashierShiftStartService::class)->startShift($shift);

        // Close own shift with shortage (expected cash 200, counted 180 -> 20 shortage) and handover 180 to self
        $endResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/end-with-handover", [
                'total_sales' => '200.00',
                'cash_collected' => '200.00',
                'card_payments' => '0.00',
                'counted_cash' => '180.00',
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->manager->id,
                'handover_amount' => '180.00',
                'handover_notes' => 'Manager self shift close',
                'shortage_allocations' => [
                    [
                        'responsible_type' => 'branch_manager',
                        'responsible_id' => $this->manager->id,
                        'amount' => '20.00',
                    ],
                ],
            ])
            ->assertOk();

        // Handover is immediately accepted/approved without needing approveHandoff
        $this->assertSame('accepted', $endResponse->json('data.summary.handover_details.status'));

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame('approved', $handover->status);
        $this->assertSame((string) $this->manager->id, (string) $handover->approved_by_id);

        $receipt = CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('180.00', (string) $receipt->confirmed_amount);

        // PersonalLedgerTransaction reflects 180.00 handover received + 20.00 manager variance
        $this->assertTrue(PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id)
            ->where('related_handover_id', $handover->id)
            ->where('amount', '180.00')
            ->where('is_cash_in', true)
            ->exists());

        // Liability allocation is already confirmed & manager-approved + posted
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertNotNull($allocation->cashier_confirmed_at);
        $this->assertSame('approved', $allocation->manager_approval_status);
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $shift->id)->count());

        // Manager workday can now end and submit daily close directly without any manual approve step
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())
            ->firstOrFail();
        $workday->update(['status' => 'in_progress', 'actual_start_time' => now()->subHour()]);

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/end', [
                'manager_workday_id' => $workday->id,
                'handover_timing' => 'today',
            ])
            ->assertOk();

        $rev = app(ShiftReportRevisionService::class)->currentManagerRevision($workday);
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => $rev->revision_number,
            ])
            ->assertOk();

        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
    }

    public function test_regular_cashier_handover_still_remains_pending_and_requires_manager_approval(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        app(CashierShiftStartService::class)->startShift($shift);

        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
                'total_sales' => '200.00',
                'cash_collected' => '200.00',
                'card_payments' => '0.00',
                'counted_cash' => '200.00',
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->manager->id,
                'handover_amount' => '200.00',
            ])
            ->assertOk();

        $this->assertSame('pending', $res->json('data.summary.handover_details.status'));
        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame('pending', $handover->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->count());
    }

    public function test_manager_covering_own_shift_via_start_handover_auto_confirms_without_approve_step(): void
    {
        $managerCashier = app(CashierService::class)->ensureBranchManagerCashier($this->manager->id, $this->branch->id, $this->manager);
        $shift = CashierShift::create([
            'cashier_id' => $managerCashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => '150.00',
            'net_sales' => '130.43',
            'vat_amount' => '19.57',
            'cash_collected' => '150.00',
            'card_payments' => '0.00',
        ]);
        $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'branch_manager', $this->manager->id, 0);
        app(ShiftCashCountService::class)->record($shift, $rev, 15000, 0, 0, 15000);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
                'handover_to_type' => 'branch_manager',
                'branch_manager_id' => $this->manager->id,
                'handover_amount' => '150.00',
                'handover_notes' => 'Manager start-handover self close',
            ])
            ->assertOk();

        $this->assertSame('accepted', $res->json('data.handover_details.status'));
        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame('approved', $handover->status);
        $this->assertSame(1, CashierShiftHandoverReceipt::where('cashier_shift_handover_id', $handover->id)->count());
    }
}
