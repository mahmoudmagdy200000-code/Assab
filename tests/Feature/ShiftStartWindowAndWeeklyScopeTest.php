<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;

final class ShiftStartWindowAndWeeklyScopeTest extends ShiftRecurringRosterRenewalTest
{
    public function test_shift_helper_start_window_standard_shift(): void
    {
        $shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Midday Shift',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'is_active' => true,
        ]);

        $cashierShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        // 1. Before window (2 hours and 1 minute before start = 07:59)
        $early = Carbon::parse('2026-11-12 07:59:00', 'Asia/Riyadh');
        $resEarly = ShiftHelper::getShiftStartWindow($cashierShift, $early);
        $this->assertFalse($resEarly['can_start']);
        $this->assertStringContainsString('قبل موعدها بأكثر من ساعتين', $resEarly['reason']);
        $this->assertStringContainsString('08:00', $resEarly['reason']);

        // 2. Exactly at window open (08:00)
        $atOpen = Carbon::parse('2026-11-12 08:00:00', 'Asia/Riyadh');
        $resAtOpen = ShiftHelper::getShiftStartWindow($cashierShift, $atOpen);
        $this->assertTrue($resAtOpen['can_start']);
        $this->assertNull($resAtOpen['reason']);

        // 3. During scheduled shift (11:00)
        $during = Carbon::parse('2026-11-12 11:00:00', 'Asia/Riyadh');
        $resDuring = ShiftHelper::getShiftStartWindow($cashierShift, $during);
        $this->assertTrue($resDuring['can_start']);
        $this->assertNull($resDuring['reason']);

        // 4. Before window close (13:59:59) -> still open
        $beforeClose = Carbon::parse('2026-11-12 13:59:59', 'Asia/Riyadh');
        $resBeforeClose = ShiftHelper::getShiftStartWindow($cashierShift, $beforeClose);
        $this->assertTrue($resBeforeClose['can_start']);
        $this->assertNull($resBeforeClose['reason']);

        // 5. Exactly at window close (14:00:00) -> strictly disabled!
        $atClose = Carbon::parse('2026-11-12 14:00:00', 'Asia/Riyadh');
        $resAtClose = ShiftHelper::getShiftStartWindow($cashierShift, $atClose);
        $this->assertFalse($resAtClose['can_start'], 'Window must be closed/disabled at 14:00:00 sharp.');
        $this->assertStringContainsString('انتهت نافذة بدء الوردية', $resAtClose['reason']);
        $this->assertStringContainsString('14:00', $resAtClose['reason']);
        $this->assertArrayHasKey('server_time', $resAtClose);

        // 6. After window close (14:01)
        $after = Carbon::parse('2026-11-12 14:01:00', 'Asia/Riyadh');
        $resAfter = ShiftHelper::getShiftStartWindow($cashierShift, $after);
        $this->assertFalse($resAfter['can_start']);
        $this->assertStringContainsString('انتهت نافذة بدء الوردية', $resAfter['reason']);
        $this->assertStringContainsString('14:00', $resAfter['reason']);
    }

    public function test_shift_helper_start_window_midnight_crossing(): void
    {
        $overnightTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'is_active' => true,
        ]);

        $cashierShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $overnightTemplate->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        // 1. Before window opens (19:59 on Nov 12)
        $early = Carbon::parse('2026-11-12 19:59:00', 'Asia/Riyadh');
        $resEarly = ShiftHelper::getShiftStartWindow($cashierShift, $early);
        $this->assertFalse($resEarly['can_start']);
        $this->assertStringContainsString('20:00', $resEarly['reason']);

        // 2. Window opens (20:00 on Nov 12)
        $open = Carbon::parse('2026-11-12 20:00:00', 'Asia/Riyadh');
        $this->assertTrue(ShiftHelper::getShiftStartWindow($cashierShift, $open)['can_start']);

        // 3. Middle of the night across midnight (02:00 on Nov 13)
        $midnight = Carbon::parse('2026-11-13 02:00:00', 'Asia/Riyadh');
        $this->assertTrue(ShiftHelper::getShiftStartWindow($cashierShift, $midnight)['can_start']);

        // 4. Near close (07:59:59 on Nov 13) -> still open
        $nearClose = Carbon::parse('2026-11-13 07:59:59', 'Asia/Riyadh');
        $this->assertTrue(ShiftHelper::getShiftStartWindow($cashierShift, $nearClose)['can_start']);

        // 5. Exactly at close (08:00:00 on Nov 13) -> strictly disabled!
        $atClose = Carbon::parse('2026-11-13 08:00:00', 'Asia/Riyadh');
        $resAtClose = ShiftHelper::getShiftStartWindow($cashierShift, $atClose);
        $this->assertFalse($resAtClose['can_start'], 'Overnight shift window must close at 08:00:00 sharp.');
        $this->assertStringContainsString('08:00', $resAtClose['reason']);

        // 6. Window closed (08:01 on Nov 13)
        $closed = Carbon::parse('2026-11-13 08:01:00', 'Asia/Riyadh');
        $resClosed = ShiftHelper::getShiftStartWindow($cashierShift, $closed);
        $this->assertFalse($resClosed['can_start']);
        $this->assertStringContainsString('08:00', $resClosed['reason']);
    }

    public function test_cashier_pending_shifts_query_filters_strictly_7_days_from_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Riyadh'));
        try {
            $shiftTplMorning = $this->template1;
            $shiftTplEvening = Shift::factory()->create([
                'branch_id' => $this->branch->id,
                'name' => 'Late Evening Shift',
                'start_time' => '17:00:00',
                'end_time' => '23:00:00',
            ]);

            // Past shifts (must be excluded)
            CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-10', 'status' => ShiftStatus::NOT_STARTED]);
            CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-11', 'status' => ShiftStatus::NOT_STARTED]);

            // Target 7-day range (Nov 12 to Nov 18 inclusive)
            $s1 = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplEvening->id, 'shift_date' => '2026-11-12', 'status' => ShiftStatus::NOT_STARTED]);
            $s2 = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-12', 'status' => ShiftStatus::NOT_STARTED]);
            $s3 = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-15', 'status' => ShiftStatus::NOT_STARTED]);
            $s4 = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-18', 'status' => ShiftStatus::NOT_STARTED]);

            // Future shift on day 8 (Nov 19 - must be excluded)
            CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTplMorning->id, 'shift_date' => '2026-11-19', 'status' => ShiftStatus::NOT_STARTED]);

            $this->actingAs($this->cashierA, 'sanctum');
            $response = $this->getJson('/api/v1/cashier/my-shifts/pending')->assertOk();

            $data = $response->json('data');
            $this->assertCount(4, $data, 'Only shifts within 7 days (today through today+6) should be returned in pending.');

            // Verify order: chronological by shift_date, then start_time ASC
            $this->assertSame($s2->id, $data[0]['id'], 'Morning shift on Nov 12 should be first.');
            $this->assertSame($s1->id, $data[1]['id'], 'Evening shift on Nov 12 should be second.');
            $this->assertSame($s3->id, $data[2]['id'], 'Nov 15 shift should be third.');
            $this->assertSame($s4->id, $data[3]['id'], 'Nov 18 shift should be fourth.');

            // Verify metadata present
            $this->assertArrayHasKey('start_window', $data[0]);
            $this->assertArrayHasKey('can_start', $data[0]);
            $this->assertTrue($data[0]['can_start'], 'Nov 12 morning shift (08:00-16:00) at 10:00 is within start window.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manager_pending_shifts_query_filters_strictly_7_days_from_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Riyadh'));
        try {
            $shiftTpl = Shift::factory()->create([
                'branch_id' => $this->branch->id,
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
            ]);

            // Past shift (Nov 10)
            CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTpl->id, 'shift_date' => '2026-11-10', 'status' => ShiftStatus::NOT_STARTED]);
            // Today (Nov 12)
            $todayShift = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTpl->id, 'shift_date' => '2026-11-12', 'status' => ShiftStatus::NOT_STARTED]);
            // Day 6 (Nov 18)
            $day6Shift = CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTpl->id, 'shift_date' => '2026-11-18', 'status' => ShiftStatus::NOT_STARTED]);
            // Day 7 (Nov 19, excluded)
            CashierShift::factory()->create(['cashier_id' => $this->cashierA->id, 'shift_id' => $shiftTpl->id, 'shift_date' => '2026-11-19', 'status' => ShiftStatus::NOT_STARTED]);

            $this->actingAs($this->manager, 'sanctum');
            $response = $this->getJson("/api/v1/branch-manager/shifts/pending?cashier_id={$this->cashierA->id}")->assertOk();

            $data = $response->json('data');
            $this->assertCount(2, $data, 'Manager pending list must only contain shifts from Nov 12 to Nov 18 inclusive.');
            $this->assertSame($todayShift->id, $data[0]['id']);
            $this->assertSame($day6Shift->id, $data[1]['id']);

            $this->assertArrayHasKey('can_start', $data[0]);
            $this->assertTrue($data[0]['can_start']);
            $this->assertArrayHasKey('start_window', $data[0]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cashier_start_shift_enforces_start_window_and_midnight_crossing(): void
    {
        $shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
        ]);

        $cashierShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->cashierA, 'sanctum');

        // 1. Attempt start before window (07:30 on Nov 12) -> Expect 400 Bad Request
        Carbon::setTestNow(Carbon::parse('2026-11-12 07:30:00', 'Asia/Riyadh'));
        try {
            $respEarly = $this->postJson("/api/v1/cashier/shifts/{$cashierShift->id}/start");
            $respEarly->assertStatus(400);
            $this->assertStringContainsString('قبل موعدها بأكثر من ساعتين', $respEarly->json('message'));
        } finally {
            Carbon::setTestNow();
        }

        // 2. Attempt start within window (08:30 on Nov 12) -> Expect 200 OK
        Carbon::setTestNow(Carbon::parse('2026-11-12 08:30:00', 'Asia/Riyadh'));
        try {
            $respValid = $this->postJson("/api/v1/cashier/shifts/{$cashierShift->id}/start");
            $respValid->assertOk();
            $this->assertSame('In Progress', $respValid->json('data.progress.status'));
        } finally {
            Carbon::setTestNow();
        }

        // 3. Overnight midnight crossing start
        $overnightTpl = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Midnight Crossing Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
        ]);
        $overnightShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $overnightTpl->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->cashierB, 'sanctum');

        // Start next morning at 03:00 (Nov 13) -> Expect 200 OK
        Carbon::setTestNow(Carbon::parse('2026-11-13 03:00:00', 'Asia/Riyadh'));
        try {
            $respNight = $this->postJson("/api/v1/cashier/shifts/{$overnightShift->id}/start");
            $respNight->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        // 4. Past shift from Nov 10 attempted on Nov 12 -> Expect 400 Bad Request
        $pastShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => '2026-11-10',
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Riyadh'));
        try {
            $respPast = $this->postJson("/api/v1/cashier/shifts/{$pastShift->id}/start");
            $respPast->assertStatus(400);
            $this->assertStringContainsString('انتهت نافذة بدء الوردية', $respPast->json('message'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manager_start_shift_enforces_start_window_and_guards(): void
    {
        $shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Manager Start Template',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
        ]);

        $cashierShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->manager, 'sanctum');

        // 1. Early start (07:30) -> 400
        Carbon::setTestNow(Carbon::parse('2026-11-12 07:30:00', 'Asia/Riyadh'));
        try {
            $respEarly = $this->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$cashierShift->id}");
            $respEarly->assertStatus(400);
            $this->assertStringContainsString('قبل موعدها بأكثر من ساعتين', $respEarly->json('message'));
        } finally {
            Carbon::setTestNow();
        }

        // 2. Valid start within window (09:00) -> 200
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'Asia/Riyadh'));
        try {
            $respValid = $this->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$cashierShift->id}");
            $respValid->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        // 3. Unauthorized other-branch manager guard preserved
        $otherBranch = Branch::factory()->create(['name' => 'Other Branch']);
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id, 'is_active' => true]);
        $otherShift = CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => '2026-11-12',
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($otherManager, 'sanctum');
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Asia/Riyadh'));
        try {
            $respForbidden = $this->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$otherShift->id}");
            // Must not allow manager from different branch to start shift
            $this->assertContains($respForbidden->status(), [403, 404]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
