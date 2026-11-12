<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Modules\Shift\Models\BranchShiftRosterAssignment;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;

final class IndependentRosterAuditTest extends \Tests\Feature\ShiftRecurringRosterRenewalTest
{
    public function test_audit_empty_roster_contract_is_json_object(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $response = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster?start_date=2026-11-15&end_date=2026-11-21')->assertOk();
        $this->assertIsObject(json_decode($response->getContent())->data->roster, 'Flutter requires Map; empty backend roster must serialize as object.');
    }

    public function test_audit_expired_roster_does_not_revive_historical_cashier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
        try {
            BranchShiftRosterAssignment::create(['branch_id'=>$this->branch->id,'shift_id'=>$this->template1->id,'cashier_id'=>$this->cashierA->id,'effective_from'=>'2026-01-01','effective_to'=>'2026-11-12']);
            CashierShift::factory()->create(['cashier_id'=>$this->substituteCashier->id,'shift_id'=>$this->template1->id,'shift_date'=>'2026-11-10','status'=>ShiftStatus::COMPLETED]);
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $this->assertSame(0, CashierShift::where('shift_id',$this->template1->id)->whereDate('shift_date','>=','2026-11-15')->count(), 'Expired roster must not revive historical substitute.');
        } finally { Carbon::setTestNow(); }
    }

    public function test_audit_deactivated_roster_cashier_is_not_renewed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
        try {
            BranchShiftRosterAssignment::create(['branch_id'=>$this->branch->id,'shift_id'=>$this->template1->id,'cashier_id'=>$this->cashierA->id,'effective_from'=>'2026-01-01']);
            $this->cashierA->update(['status'=>'deactivated']);
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $this->assertSame(0, CashierShift::where('cashier_id', $this->cashierA->id)->count(), 'Deactivated cashier must not receive new shifts.');
        } finally { Carbon::setTestNow(); }
    }

    public function test_audit_future_different_day_does_not_block_empty_slot(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $this->cashierA->update(['status'=>'active']);
        $this->cashierB->update(['status'=>'active']);
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts',['cashier_id'=>$this->cashierA->id,'shift_ids'=>[$this->template1->id],'shift_date'=>'2026-11-18'])->assertOk();
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts',['cashier_id'=>$this->cashierB->id,'shift_ids'=>[$this->template1->id],'shift_date'=>'2026-11-15'])->assertOk();
    }

    public function test_audit_r5_comprehensive_recurring_roster_lifecycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
        try {
            $this->actingAs($this->manager, 'sanctum');

            // 1. Manager saves Cashier A as recurring primary
            $this->postJson('/api/v1/branch-manager/cashiers/roster-assignments', [
                'assignments' => [
                    [
                        'shift_id' => $this->template1->id,
                        'cashier_id' => $this->cashierA->id,
                        'effective_from' => '2026-11-15',
                    ],
                ],
            ])->assertOk();

            // 2. Manager re-opens screen and verifies Cashier A is preserved
            $getResp = $this->getJson('/api/v1/branch-manager/cashiers/roster-assignments')->assertOk();
            $data = $getResp->json('data');
            $this->assertNotEmpty($data);
            $this->assertSame($this->template1->id, $data[0]['shift_id']);
            $this->assertSame($this->cashierA->id, $data[0]['cashier_id']);
            $this->assertSame('2026-11-15', Carbon::parse($data[0]['effective_from'])->setTimezone('Asia/Riyadh')->toDateString());

            // 3. Weekly renewal generates shifts for Cashier A
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $shiftsA = CashierShift::where('shift_id', $this->template1->id)
                ->whereDate('shift_date', '>=', '2026-11-15')
                ->whereDate('shift_date', '<=', '2026-11-21')
                ->get();
            $this->assertCount(5, $shiftsA);
            foreach ($shiftsA as $s) {
                $this->assertSame($this->cashierA->id, $s->cashier_id);
                $this->assertSame(ShiftStatus::NOT_STARTED, $s->status);
            }

            // 4. Change primary to Cashier B with effective_from Wednesday 2026-11-18
            $this->postJson('/api/v1/branch-manager/cashiers/roster-assignments', [
                'assignments' => [
                    [
                        'shift_id' => $this->template1->id,
                        'cashier_id' => $this->cashierB->id,
                        'effective_from' => '2026-11-18',
                    ],
                ],
            ])->assertOk();

            // 5. Next week renewal (starting 2026-11-22) uses Cashier B for all days
            Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $shiftsNextWeek = CashierShift::where('shift_id', $this->template1->id)
                ->whereDate('shift_date', '>=', '2026-11-22')
                ->whereDate('shift_date', '<=', '2026-11-28')
                ->get();
            $this->assertCount(5, $shiftsNextWeek);
            foreach ($shiftsNextWeek as $s) {
                $this->assertSame($this->cashierB->id, $s->cashier_id, 'All shifts after effective date belong to Cashier B.');
            }

            // 6. Single-day exception on 2026-11-16 (Monday) via atomic swap between templates
            $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
                'cashier_id' => $this->substituteCashier->id,
                'shift_ids' => [$this->template2->id],
                'shift_date' => '2026-11-16',
            ])->assertOk();

            $this->postJson('/api/v1/branch-manager/cashiers/swap-shifts', [
                'cashier_a_id' => $this->cashierA->id,
                'shift_a_id' => $this->template1->id,
                'cashier_b_id' => $this->substituteCashier->id,
                'shift_b_id' => $this->template2->id,
                'shift_date' => '2026-11-16',
                'for_full_week' => false,
            ])->assertOk();

            // Verify exception is confined strictly to Monday
            $monShift = CashierShift::where('shift_id', $this->template1->id)
                ->whereDate('shift_date', '2026-11-16')
                ->firstOrFail();
            $this->assertSame($this->substituteCashier->id, $monShift->cashier_id);

            // Sunday still belongs to Cashier A
            $sunShift = CashierShift::where('shift_id', $this->template1->id)
                ->whereDate('shift_date', '2026-11-15')
                ->firstOrFail();
            $this->assertSame($this->cashierA->id, $sunShift->cashier_id);

            // 7. Protection of started shifts and shifts with financial evidence
            $sunShift->update(['status' => ShiftStatus::IN_PROGRESS]);
            // Attempting to overwrite started shift via assign-shifts fails with 422
            $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
                'cashier_id' => $this->cashierB->id,
                'shift_ids' => [$this->template1->id],
                'shift_date' => '2026-11-15',
            ])->assertStatus(422);

            // Shift status and cashier remain protected
            $this->assertSame($this->cashierA->id, $sunShift->fresh()->cashier_id);
            $this->assertSame(ShiftStatus::IN_PROGRESS, $sunShift->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_audit_r6_server_driven_scheduling_calendar_and_open_shift_preservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 02:00:00')); // Sunday morning in Riyadh
        try {
            $this->actingAs($this->manager, 'sanctum');

            // 1. Calling dated-roster without parameters defaults to current scheduling week (2026-11-15 to 2026-11-21)
            $response = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster')->assertOk();
            $data = $response->json('data');

            $this->assertSame('2026-11-15', $data['start_date']);
            $this->assertSame('2026-11-21', $data['end_date']);
            $this->assertArrayHasKey('scheduling_calendar', $data);
            $this->assertSame('2026-11-15', $data['scheduling_calendar']['today']);
            $this->assertSame('Asia/Riyadh', $data['scheduling_calendar']['timezone']);
            $this->assertSame(0, $data['scheduling_calendar']['week_start']);
            $this->assertSame('2026-11-15', $data['scheduling_calendar']['default_start_date']);
            $this->assertSame('2026-11-21', $data['scheduling_calendar']['default_end_date']);

            // 2. Open shift from Saturday remains in Saturday and does not distort the Sunday scheduling week
            $satShift = CashierShift::create([
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
                'shift_date' => '2026-11-14',
                'status' => ShiftStatus::IN_PROGRESS,
            ]);

            // Querying dated-roster for the new week returns the new week
            $newWeekResp = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster')->assertOk();
            $this->assertSame('2026-11-15', $newWeekResp->json('data.start_date'));

            // Querying Saturday's week returns Saturday's open shift intact
            $prevWeekResp = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster?start_date=2026-11-08&end_date=2026-11-14')->assertOk();
            $satRoster = (array) $prevWeekResp->json('data.roster');
            $key = '2026-11-14_'.$this->template1->id;
            $this->assertArrayHasKey($key, $satRoster);
            $this->assertSame($satShift->id, $satRoster[$key]['cashier_shift_id']);
            $this->assertSame('in_progress', $satRoster[$key]['status']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
