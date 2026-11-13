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
    public function test_pending_lists_keep_overnight_shift_until_exact_close_with_pagination(): void
    {
        $this->template2->update(['start_time' => '22:00', 'end_time' => '06:00']);
        $overnight = $this->makePendingShift($this->template2, '2026-11-12');
        $today = $this->makePendingShift($this->template1, '2026-11-13');
        $this->makePendingShift($this->template1, '2026-11-12'); // Expired.
        $this->makePendingShift($this->template1, '2026-11-20'); // Eighth day.
        CashierShift::factory()->create([
            'cashier_id' => $this->cashierB->id, 'shift_id' => $this->template2->id,
            'shift_date' => '2026-11-12', 'status' => ShiftStatus::NOT_STARTED,
        ]);
        $otherBranch = Branch::factory()->create();
        $foreignTemplate = Shift::factory()->create([
            'branch_id' => $otherBranch->id, 'start_time' => '22:00', 'end_time' => '06:00',
        ]);
        $foreignCashier = Cashier::factory()->create(['branch_id' => $otherBranch->id]);
        CashierShift::factory()->create([
            'cashier_id' => $foreignCashier->id, 'shift_id' => $foreignTemplate->id,
            'shift_date' => '2026-11-12', 'status' => ShiftStatus::NOT_STARTED,
        ]);

        try {
            foreach (['2026-11-13 03:00:37', '2026-11-13 07:59:59'] as $time) {
                Carbon::setTestNow(Carbon::parse($time, 'Asia/Riyadh'));
                foreach ($this->pendingActors() as [$actor, $url]) {
                    $this->actingAs($actor, 'sanctum');
                    $first = $this->getJson($url.'&per_page=1')->assertOk();
                    $this->assertSame($overnight->id, $first->json('data.0.id'));
                    $this->assertTrue($first->json('data.0.can_start'));
                    if ($actor instanceof Cashier) {
                        $this->assertCount(1, $first->json('data'));
                        $second = $this->getJson($url.'&per_page=1&page=2')->assertOk();
                        $this->assertSame($today->id, $second->json('data.0.id'));
                    }
                    $this->assertCount(2, $this->getJson($url)->assertOk()->json('data'));
                }
            }

            $this->actingAs($this->manager, 'sanctum');
            $this->assertCount(3, $this->getJson('/api/v1/branch-manager/shifts/pending')->assertOk()->json('data'));
            $this->getJson("/api/v1/branch-manager/shifts/pending/{$overnight->id}")
                ->assertOk()->assertJsonPath('data.actions_available.start_shift', true);
            $page = app(\Modules\Shift\Repositories\CashierShiftRepositoryInterface::class)
                ->getUpcomingPaginated($this->branch->id, $this->cashierA->id, 1);
            $this->assertSame(2, $page->total());
            $this->assertCount(1, $page->items());
            $this->assertSame($overnight->id, $page->items()[0]->id);

            Carbon::setTestNow(Carbon::parse('2026-11-13 08:00:00', 'Asia/Riyadh'));
            foreach ($this->pendingActors() as [$actor, $url]) {
                $this->actingAs($actor, 'sanctum');
                $result = $this->getJson($url)->assertOk();
                $this->assertSame([$today->id], array_column($result->json('data'), 'id'));
            }
            $this->actingAs($this->cashierA, 'sanctum');
            $this->postJson("/api/v1/cashier/shifts/{$overnight->id}/start")->assertStatus(400);
            $this->actingAs($this->manager, 'sanctum');
            $this->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$overnight->id}")->assertStatus(400);
            $this->getJson("/api/v1/branch-manager/shifts/pending/{$overnight->id}")
                ->assertOk()->assertJsonPath('data.actions_available.start_shift', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pending_lists_include_evening_grace_but_respect_explicit_date_ranges(): void
    {
        $this->template2->update(['start_time' => '17:00', 'end_time' => '23:00']);
        $evening = $this->makePendingShift($this->template2, '2026-11-12');
        $today = $this->makePendingShift($this->template1, '2026-11-13');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-13 00:30:37', 'Asia/Riyadh'));
            foreach ($this->pendingActors() as [$actor, $url]) {
                $this->actingAs($actor, 'sanctum');
                $this->assertSame([$evening->id, $today->id], array_column($this->getJson($url)->assertOk()->json('data'), 'id'));
                $this->assertSame([$evening->id], array_column($this->getJson($url.'&date_from=2026-11-12&date_to=2026-11-12')->assertOk()->json('data'), 'id'));
                $this->assertSame([], $this->getJson($url.'&date_from=2026-11-14&date_to=2026-11-19')->assertOk()->json('data'));
                $this->assertSame([$evening->id, $today->id], array_column($this->getJson($url.'&date_from=2026-11-13')->assertOk()->json('data'), 'id'));
            }
            Carbon::setTestNow(Carbon::parse('2026-11-13 01:00:00', 'Asia/Riyadh'));
            foreach ($this->pendingActors() as [$actor, $url]) {
                $this->actingAs($actor, 'sanctum');
                $this->assertSame([$today->id], array_column($this->getJson($url)->assertOk()->json('data'), 'id'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_carry_over_requires_pending_unstarted_state_and_handles_second_midnight(): void
    {
        $this->template2->update(['start_time' => '23:00', 'end_time' => '22:30']);
        $eligible = $this->makePendingShift($this->template2, '2026-11-11');
        $eligible->update(['status' => ShiftStatus::REASSIGNED]);
        $started = $this->makePendingShift($this->template2, '2026-11-12');
        $started->update(['actual_start_time' => '2026-11-12 23:00:00']);
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-13 00:15:37', 'Asia/Riyadh'));
            $query = fn () => CashierShift::upcoming()->where('cashier_id', $this->cashierA->id)->pendingDateWindow()->pluck('id')->all();
            $this->assertSame([$eligible->id], $query());
            $eligible->update(['operational_ended_at' => '2026-11-12 23:50:00']);
            $this->assertSame([], $query());
            $eligible->update(['operational_ended_at' => null, 'status' => ShiftStatus::COMPLETED]);
            $this->assertSame([], $query());
            $eligible->update(['status' => ShiftStatus::NOT_STARTED]);
            Carbon::setTestNow(Carbon::parse('2026-11-13 00:30:00', 'Asia/Riyadh'));
            $this->assertSame([], $query());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manager_can_start_carry_over_via_cashier_id_fallback(): void
    {
        $overnight = $this->makePendingShift($this->template2, '2026-11-12');
        $today = $this->makePendingShift($this->template1, '2026-11-13');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-13 03:00:37', 'Asia/Riyadh'));
            $this->actingAs($this->manager, 'sanctum');
            $this->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$this->cashierA->id}")->assertOk();
            $this->assertSame(ShiftStatus::IN_PROGRESS, $overnight->fresh()->status);
            $this->assertSame(ShiftStatus::NOT_STARTED, $today->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_window_boundaries_do_not_inherit_wall_clock_seconds(): void
    {
        $shift = $this->makePendingShift($this->template1, '2026-11-12');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-12 10:12:37', 'Asia/Riyadh'));
            $open = ShiftHelper::getShiftStartWindow($shift, Carbon::parse('2026-11-12 06:00:00', 'Asia/Riyadh'));
            $this->assertTrue($open['can_start']);
            $this->assertSame('2026-11-12T06:00:00+03:00', $open['window_opens_at']);
            $close = ShiftHelper::getShiftStartWindow($shift, Carbon::parse('2026-11-12 18:00:00', 'Asia/Riyadh'));
            $this->assertFalse($close['can_start']);
            $this->assertSame('2026-11-12T18:00:00+03:00', $close['window_closes_at']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makePendingShift(Shift $template, string $date): CashierShift
    {
        return CashierShift::factory()->create([
            'cashier_id' => $this->cashierA->id, 'shift_id' => $template->id,
            'shift_date' => $date, 'status' => ShiftStatus::NOT_STARTED,
        ]);
    }

    private function pendingActors(): array
    {
        return [
            [$this->cashierA, '/api/v1/cashier/my-shifts/pending?scope=test'],
            [$this->manager, "/api/v1/branch-manager/shifts/pending?cashier_id={$this->cashierA->id}"],
        ];
    }

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
