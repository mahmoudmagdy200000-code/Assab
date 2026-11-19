<?php

namespace Tests\Feature\Shift;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Services\ShiftScheduleBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ReassignmentReportOwnershipService;
use Tests\TestCase;

class ShiftStartWindowDeadlockFixTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'ASSAB Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'ASSAB Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'ASSAB Demo Branch 1119',
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->cashierA = Cashier::factory()->create([
            'name' => 'Demo Cashier A 1119',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->cashierB = Cashier::factory()->create([
            'name' => 'Demo Cashier B 1119',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createShiftTemplate(string $name, string $startTime, string $endTime, ?Branch $branch = null, bool $isActive = true): Shift
    {
        return Shift::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_active' => $isActive,
        ]);
    }

    private function createAssignment(Cashier $cashier, Shift $template, string $date, ShiftStatus $status = ShiftStatus::NOT_STARTED): CashierShift
    {
        return CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => $date,
            'status' => $status,
            'assigned_by' => $this->manager->id,
            'opening_balance' => 0.00,
        ]);
    }

    public function test_01_normal_start_is_permitted_at_minus_2_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 10-18', '10:00:00', '18:00:00');
        $shift = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');

        $pending = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($pending['can_start']);
        $this->assertTrue($pending['can_start_normal']);
        $this->assertFalse($pending['is_late_start']);
        $this->assertSame('normal', $pending['window_phase']);
        $this->assertSame('2026-11-19T08:00:00+03:00', $pending['start_window']['normal_window_opens_at']);
        $this->assertSame('2026-11-19T12:00:00+03:00', $pending['start_window']['normal_window_closes_at']);

        $this->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertOk()
            ->assertJsonPath('data.progress.status', 'In Progress');

        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
    }

    public function test_02_boundary_contract_at_plus_2_hours_is_inclusive_and_transitions_to_late_afterwards(): void
    {
        $tpl = $this->createShiftTemplate('Morning 10-18', '10:00:00', '18:00:00');
        $shiftOnBoundary = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');
        $shiftPastBoundary = $this->createAssignment($this->cashierB, $tpl, '2026-11-19');

        // Inclusive upper bound: 12:00:00 sharp is still on-time normal window
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'Asia/Riyadh'));
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()
            ->assertJsonPath('data.0.can_start', true)
            ->assertJsonPath('data.0.window_phase', 'normal')
            ->assertJsonPath('data.0.is_late_start', false);

        $this->postJson("/api/v1/cashier/shifts/{$shiftOnBoundary->id}/start")
            ->assertOk();

        // 12:00:01 -> immediately transitions to controlled late-start recovery
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:01', 'Asia/Riyadh'));
        $this->actingAs($this->cashierB, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()
            ->assertJsonPath('data.0.can_start', false)
            ->assertJsonPath('data.0.window_phase', 'late')
            ->assertJsonPath('data.0.is_late_start', true)
            ->assertJsonPath('data.0.requires_manager_authorization', true)
            ->assertJsonPath('data.0.start_disabled_code', 'LATE_START_REQUIRES_MANAGER');

        $this->postJson("/api/v1/cashier/shifts/{$shiftPastBoundary->id}/start")
            ->assertStatus(400);
    }

    public function test_03_authorized_late_start_recovery_preserves_timestamps_and_audit_trail_without_fake_money(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 13:30:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 10-18', '10:00:00', '18:00:00');
        $shift = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');

        // Cashier self-start rejected in late window
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertStatus(400);

        // Authorized branch manager late start recovery succeeds
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$shift->id}", [
                'late_start_reason' => 'Cashier delayed in traffic; approved by branch manager',
            ])
            ->assertOk();

        $fresh = $shift->fresh();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $fresh->status);
        $this->assertSame('2026-11-19 13:30:00', $fresh->actual_start_time->format('Y-m-d H:i:s'));
        $this->assertSame('10:00', $fresh->shift->start_time->format('H:i'));
        $this->assertSame('2026-11-19', $fresh->shift_date->format('Y-m-d'));

        $history = $fresh->history()->where('action', 'started')->sole();
        $newValue = is_string($history->new_value) ? json_decode($history->new_value, true) : $history->new_value;
        $this->assertTrue((bool) ($newValue['is_late_start'] ?? false));
        $this->assertSame('Cashier delayed in traffic; approved by branch manager', $newValue['late_start_reason']);
        $this->assertSame($this->manager->id, $newValue['authorized_by_id']);
        $this->assertNotNull($newValue['scheduled_start_at']);

        // No fake sales, custody, or handover rows created
        $this->assertSame(0, DB::table('cashier_shift_handovers')->count());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame('0.00', $fresh->opening_balance);
    }

    public function test_04_expired_earlier_not_started_shift_does_not_block_later_valid_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 09:00:00', 'Asia/Riyadh'));
        $earlierTpl = $this->createShiftTemplate('Dawn 04-06', '04:00:00', '06:00:00');
        $laterTpl = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');

        $earlier = $this->createAssignment($this->cashierA, $earlierTpl, '2026-11-19');
        $later = $this->createAssignment($this->cashierA, $laterTpl, '2026-11-19');

        $pending = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $pending);
        $this->assertSame($earlier->id, $pending[0]['id']);
        $this->assertFalse($pending[0]['can_start']);
        $this->assertFalse($pending[0]['is_next_shift']);

        $this->assertSame($later->id, $pending[1]['id']);
        $this->assertTrue($pending[1]['is_next_shift']);
        $this->assertTrue($pending[1]['can_start']);
        $this->assertNull($pending[1]['start_disabled_reason']);

        $this->postJson("/api/v1/cashier/shifts/{$later->id}/start")->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $later->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $earlier->fresh()->status);
    }

    public function test_05_accountant_schedule_modification_allows_new_valid_shift_without_obsolete_block(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 09:30:00', 'Asia/Riyadh'));

        // Old shift schedule synced first: 08:00-14:00
        $oldTpl = $this->createShiftTemplate('Old Shift 1', '08:00:00', '14:00:00', isActive: true);
        $oldAssignment = $this->createAssignment($this->cashierA, $oldTpl, '2026-11-19');

        // Accountant modifies branch schedule via BranchShiftConfig -> ShiftScheduleBridgeService deactivates 08:00-14:00 and activates 09:00-17:00
        BranchShiftConfig::updateOrCreate(
            ['branch_id' => $this->branch->id],
            [
                'num_shifts' => 1,
                'duration_hours' => 8,
                'duration_minutes' => 480,
                'first_shift_start' => '09:00',
                'shifts' => [],
            ]
        );
        app(ShiftScheduleBridgeService::class)->regenerateForBranch((string) $this->branch->id);

        $newTpl = Shift::where('branch_id', $this->branch->id)->where('is_active', true)->sole();
        $newAssignment = $this->createAssignment($this->cashierA, $newTpl, '2026-11-19');

        $this->assertFalse((bool) $oldTpl->fresh()->is_active);

        $pending = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()
            ->json('data');

        $byId = collect($pending)->keyBy('id');
        $this->assertFalse($byId[$oldAssignment->id]['can_start']);
        $this->assertSame('SCHEDULE_INACTIVE', $byId[$oldAssignment->id]['start_disabled_code']);
        $this->assertTrue($byId[$newAssignment->id]['is_next_shift']);
        $this->assertTrue($byId[$newAssignment->id]['can_start']);

        $this->postJson("/api/v1/cashier/shifts/{$newAssignment->id}/start")->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $newAssignment->fresh()->status);
    }

    public function test_06_other_cashier_active_shift_rejects_unauthorized_overlapping_activation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 09:30:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');
        $activeChainId = (string) Str::uuid();

        $activeByA = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $tpl->id,
            'shift_date' => '2026-11-19',
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
            'operational_chain_id' => $activeChainId,
        ]);

        // Unlinked shift on the same operational chain assigned to cashierB
        $conflictingB = CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $tpl->id,
            'shift_date' => '2026-11-19',
            'status' => ShiftStatus::NOT_STARTED,
            'operational_chain_id' => $activeChainId,
        ]);

        // Cashier B cannot start Cashier A's shift
        $this->actingAs($this->cashierB, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$activeByA->id}/start")
            ->assertStatus(404);

        // Cashier B cannot start a conflicting shift while Cashier A is active in that operational chain
        $this->actingAs($this->cashierB, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$conflictingB->id}/start")
            ->assertStatus(409);

        $this->assertSame(ShiftStatus::NOT_STARTED, $conflictingB->fresh()->status);
    }

    public function test_07_same_cashier_attempting_start_twice_persists_only_one_activation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 09:30:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');
        $shift = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertOk();

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertStatus(400);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
        $this->assertSame(1, $shift->history()->where('action', 'started')->count());
    }

    public function test_08_foreign_branch_cashier_and_manager_cannot_activate_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 09:30:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');
        $shift = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');

        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id]);
        $foreignCashier = Cashier::factory()->create(['branch_id' => $otherBranch->id]);
        $foreignManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id, 'is_active' => true]);

        $this->actingAs($foreignCashier, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/start")
            ->assertStatus(404);

        $this->actingAs($foreignManager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$shift->id}")
            ->assertStatus(404);

        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
    }

    public function test_09_overnight_midnight_crossing_uses_branch_business_date_and_timezone(): void
    {
        $overnightTpl = $this->createShiftTemplate('Night 22-06', '22:00:00', '06:00:00');
        $shift = $this->createAssignment($this->cashierA, $overnightTpl, '2026-11-19');

        // Normal window: 2026-11-19 20:00 through 2026-11-20 00:00 Asia/Riyadh
        Carbon::setTestNow(Carbon::parse('2026-11-19 23:55:00', 'Asia/Riyadh'));
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/v1/cashier/my-shifts/{$shift->id}")
            ->assertOk()
            ->assertJsonPath('data.can_start', true)
            ->assertJsonPath('data.window_phase', 'normal');

        // Past normal (+2h = 00:00), during overnight run (03:00 Nov 20) -> late recovery eligible for manager
        Carbon::setTestNow(Carbon::parse('2026-11-20 03:00:00', 'Asia/Riyadh'));
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$shift->id}", ['reason' => 'Overnight late relief'])
            ->assertOk();

        $this->assertSame('2026-11-19', $shift->fresh()->shift_date->format('Y-m-d'));
        $this->assertSame('2026-11-20 03:00:00', $shift->fresh()->actual_start_time->format('Y-m-d H:i:s'));
    }

    public function test_10_earlier_shift_marked_did_not_work_unblocks_later_shift_with_no_fake_handover(): void
    {
        Config::set('features.shift_did_not_work', true);
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:30:00', 'Asia/Riyadh'));

        $tplA = $this->createShiftTemplate('Early 00-04', '00:00:00', '04:00:00');
        $tplB = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');
        $shiftA = $this->createAssignment($this->cashierA, $tplA, '2026-11-19');
        $shiftB = $this->createAssignment($this->cashierA, $tplB, '2026-11-19');

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$shiftA->id}/mark-not-worked", [
                'reason' => 'No show for midnight shift',
            ])
            ->assertOk();

        $this->assertSame(ShiftStatus::CANCELED, $shiftA->fresh()->status);
        $this->assertSame('not_worked', $shiftA->fresh()->notWorkedOutcome()['outcome']);

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shiftB->id}/start")
            ->assertOk();

        $this->assertSame(ShiftStatus::IN_PROGRESS, $shiftB->fresh()->status);
        $this->assertSame(0, DB::table('cashier_shift_handovers')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
    }

    public function test_11_pending_list_and_shift_details_endpoints_return_identical_eligibility_for_cashier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:30:00', 'Asia/Riyadh'));

        $tplA = $this->createShiftTemplate('Midnight 00-01', '00:00:00', '01:00:00');
        $tplB = $this->createShiftTemplate('Morning 08-16', '08:00:00', '16:00:00');
        $tplC = $this->createShiftTemplate('Evening 16-23', '16:00:00', '23:00:00');

        $shiftA = $this->createAssignment($this->cashierA, $tplA, '2026-11-19');
        $shiftB = $this->createAssignment($this->cashierA, $tplB, '2026-11-19');
        $shiftC = $this->createAssignment($this->cashierA, $tplC, '2026-11-19');

        $this->actingAs($this->cashierA, 'sanctum');
        $pending = collect($this->getJson('/api/v1/cashier/my-shifts/pending')->assertOk()->json('data'))->keyBy('id');

        foreach ([$shiftA->id, $shiftB->id, $shiftC->id] as $id) {
            $detailCashierEndpoint = $this->getJson("/api/v1/cashier/my-shifts/{$id}")->assertOk()->json('data');
            $detailSharedScreenEndpoint = $this->getJson("/api/v1/branch-manager/shifts/cashiers/{$id}")->assertOk()->json('data');

            foreach (['can_start', 'is_next_shift', 'window_phase', 'is_late_start', 'start_disabled_code'] as $key) {
                $this->assertSame($pending[$id][$key], $detailCashierEndpoint[$key], "Mismatch on {$key} in /my-shifts/{id} for {$id}");
                $this->assertSame($pending[$id][$key], $detailSharedScreenEndpoint[$key], "Mismatch on {$key} in /branch-manager/shifts/cashiers/{id} for {$id}");
            }
        }
    }

    public function test_12_finalized_workday_cannot_be_reopened_through_late_start_recovery(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 14:00:00', 'Asia/Riyadh'));
        $tpl = $this->createShiftTemplate('Morning 10-18', '10:00:00', '18:00:00');
        $shift = $this->createAssignment($this->cashierA, $tpl, '2026-11-19');

        $workday = BranchManagerShift::where('branch_id', $this->branch->id)
            ->whereDate('shift_date', '2026-11-19')
            ->first();

        if ($workday) {
            $workday->update([
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now()->subHour(),
            ]);
        } else {
            BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => '2026-11-19',
                'status' => 'completed',
                'daily_report_submitted' => true,
                'daily_report_submitted_at' => now()->subHour(),
            ]);
        }

        // Manager late-start recovery must reject finalized workday with 409 REPORT_REOPEN_REQUIRED
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$shift->id}", ['reason' => 'Late recovery attempt'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
        $this->assertNull($shift->fresh()->actual_start_time);
    }

    public function test_13_assignment_under_old_schedule_does_not_block_valid_new_schedule_and_keeps_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'Asia/Riyadh'));
        $oldTpl = $this->createShiftTemplate('Old Schedule 09-17', '09:00:00', '17:00:00', isActive: false);
        $oldAssignment = $this->createAssignment($this->cashierA, $oldTpl, '2026-11-19');

        $historicalCompletedTpl = $this->createShiftTemplate('Historical 06-14', '06:00:00', '14:00:00', isActive: false);
        $historicalCompleted = $this->createAssignment($this->cashierA, $historicalCompletedTpl, '2026-11-18', ShiftStatus::COMPLETED);

        $newTpl = $this->createShiftTemplate('New Effective 10-18', '10:00:00', '18:00:00', isActive: true);
        $newAssignment = $this->createAssignment($this->cashierA, $newTpl, '2026-11-19');

        // Old inactive assignment cannot start, and cannot block the new active assignment
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$oldAssignment->id}/start")
            ->assertStatus(400);

        $this->postJson("/api/v1/cashier/shifts/{$newAssignment->id}/start")
            ->assertOk();

        $this->assertSame(ShiftStatus::IN_PROGRESS, $newAssignment->fresh()->status);
        $this->assertSame('06:00', $historicalCompleted->fresh()->shift->start_time->format('H:i'));
    }

    public function test_14_reproduced_nov_19_midnight_and_08am_scenario_no_longer_deadlocks(): void
    {
        // Exact observed scenario on Nov 19, 2026 in ASSAB Demo Branch 1119
        Carbon::setTestNow(Carbon::parse('2026-11-19 08:30:00', 'Asia/Riyadh'));

        $tplA = $this->createShiftTemplate('Midnight Shift A', '00:00:00', '01:00:00');
        $tplB = $this->createShiftTemplate('Morning Shift B', '08:00:00', '16:00:00');

        $shiftA = $this->createAssignment($this->cashierA, $tplA, '2026-11-19');
        $shiftB = $this->createAssignment($this->cashierA, $tplB, '2026-11-19');

        $this->actingAs($this->cashierA, 'sanctum');
        $pending = collect($this->getJson('/api/v1/cashier/my-shifts/pending')->assertOk()->json('data'))->keyBy('id');

        // Shift A applies strict +/-2h rule (closed at 02:00, not 03:00), and indicates late-start recovery
        $this->assertFalse($pending[$shiftA->id]['can_start']);
        $this->assertSame('late', $pending[$shiftA->id]['window_phase']);
        $this->assertSame('2026-11-19T02:00:00+03:00', $pending[$shiftA->id]['start_window']['normal_window_closes_at']);
        $this->assertTrue($pending[$shiftA->id]['requires_manager_authorization']);
        $this->assertFalse($pending[$shiftA->id]['is_next_shift']);

        // Shift B (08:00-16:00) is NOT deadlocked by expired Shift A
        $this->assertTrue($pending[$shiftB->id]['is_next_shift']);
        $this->assertTrue($pending[$shiftB->id]['can_start']);
        $this->assertSame('normal', $pending[$shiftB->id]['window_phase']);

        // Both list and See Details agree identically for Shift B
        $this->getJson("/api/v1/branch-manager/shifts/cashiers/{$shiftB->id}")
            ->assertOk()
            ->assertJsonPath('data.can_start', true)
            ->assertJsonPath('data.is_next_shift', true);

        // Cashier starts Shift B cleanly -> workflow recovered
        $this->postJson("/api/v1/cashier/shifts/{$shiftB->id}/start")
            ->assertOk()
            ->assertJsonPath('data.progress.status', 'In Progress');

        $this->assertSame(ShiftStatus::IN_PROGRESS, $shiftB->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $shiftA->fresh()->status);
    }
}
