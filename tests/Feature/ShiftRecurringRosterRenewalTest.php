<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Models\BranchShiftRosterAssignment;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

class ShiftRecurringRosterRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected BranchManager $manager;
    protected Shift $template1; // Morning: 08:00 - 16:00
    protected Shift $template2; // Evening/Overnight: 20:00 - 04:00
    protected Cashier $cashierA;
    protected Cashier $cashierB;
    protected Cashier $substituteCashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'name' => 'Main Test Branch',
        ]);

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->template1 = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        $this->template2 = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Overnight Shift',
            'start_time' => '20:00:00',
            'end_time' => '04:00:00',
            'is_active' => true,
        ]);

        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Regular Cashier A',
        ]);

        $this->cashierB = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Regular Cashier B',
        ]);

        $this->substituteCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Historical Substitute Cashier',
        ]);
    }

    /**
     * MT-23: Renew twice produces exact same shifts without duplicating or reviving stale cashiers.
     */
    public function test_mt23_renew_twice_is_strictly_idempotent(): void
    {
        // Freeze time to Wednesday of current week
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            [$currentStart, $currentEnd] = ShiftHelper::currentWorkWeekDates();
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $nextDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

            $this->assertNotEmpty($nextDates, 'Next work week must have work dates.');

            // Setup durable effective roster:
            // Template 1 -> Cashier A (effective from start of year, indefinite)
            // Template 2 -> Cashier B (effective from start of year, indefinite)
            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);

            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template2->id,
                'cashier_id' => $this->cashierB->id,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);

            // Historical noise: substitute cashier worked a shift in current week
            CashierShift::factory()->create([
                'cashier_id' => $this->substituteCashier->id,
                'shift_id' => $this->template1->id,
                'shift_date' => '2026-11-10',
                'status' => ShiftStatus::COMPLETED,
            ]);

            // First run of renewal command
            $this->artisan('shifts:renew-week')->assertSuccessful();

            $expectedShiftCount = count($nextDates) * 2; // 2 active templates * work days
            $this->assertDatabaseCount('cashier_shifts', $expectedShiftCount + 1); // +1 historical noise

            $firstRunShifts = CashierShift::whereDate('shift_date', '>=', $nextDates[0]->format('Y-m-d'))
                ->whereDate('shift_date', '<=', end($nextDates)->format('Y-m-d'))
                ->orderBy('id')
                ->get();

            $this->assertCount($expectedShiftCount, $firstRunShifts);

            // Verify substitute cashier was NOT revived for next week
            $substituteNextWeekCount = CashierShift::where('cashier_id', $this->substituteCashier->id)
                ->whereDate('shift_date', '>=', $nextDates[0]->format('Y-m-d'))
                ->whereDate('shift_date', '<=', end($nextDates)->format('Y-m-d'))
                ->count();
            $this->assertSame(0, $substituteNextWeekCount, 'Substitute cashier must not be revived by renewal.');

            // Second run of renewal command (Idempotency check)
            $this->artisan('shifts:renew-week')->assertSuccessful();

            $secondRunShifts = CashierShift::whereDate('shift_date', '>=', $nextDates[0]->format('Y-m-d'))
                ->whereDate('shift_date', '<=', end($nextDates)->format('Y-m-d'))
                ->orderBy('id')
                ->get();

            // Total count unchanged
            $this->assertCount($expectedShiftCount, $secondRunShifts);
            // Identical IDs and cashier assignments
            $this->assertEquals(
                $firstRunShifts->pluck('id')->all(),
                $secondRunShifts->pluck('id')->all()
            );
            $this->assertEquals(
                $firstRunShifts->pluck('cashier_id')->all(),
                $secondRunShifts->pluck('cashier_id')->all()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * MT-23: Midweek effective date change takes effect on its exact date without overwriting prior dates.
     */
    public function test_mt23_midweek_effective_date_boundary(): void
    {
        // Freeze time to Thursday of current week
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));

        try {
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $nextDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

            // In KSA Sunday-Thursday work week:
            // Day 0: Sunday (2026-11-15)
            // Day 1: Monday (2026-11-16)
            // Day 2: Tuesday (2026-11-17)
            // Day 3: Wednesday (2026-11-18)
            // Day 4: Thursday (2026-11-19)
            $sun = $nextDates[0]->format('Y-m-d');
            $mon = $nextDates[1]->format('Y-m-d');
            $tue = $nextDates[2]->format('Y-m-d');
            $wed = $nextDates[3]->format('Y-m-d');
            $thu = $nextDates[4]->format('Y-m-d');

            // Cashier A is assigned to Template 1 through Tuesday (effective_to = 2026-11-17)
            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
                'effective_from' => '2026-01-01',
                'effective_to' => $tue,
            ]);

            // Cashier B takes over Template 1 starting Wednesday (effective_from = 2026-11-18)
            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierB->id,
                'effective_from' => $wed,
                'effective_to' => null,
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            // Verification: Sun, Mon, Tue are assigned to Cashier A
            foreach ([$sun, $mon, $tue] as $date) {
                $shift = CashierShift::where('shift_id', $this->template1->id)
                    ->whereDate('shift_date', $date)
                    ->first();
                $this->assertNotNull($shift, "Shift must exist for {$date}");
                $this->assertEquals($this->cashierA->id, $shift->cashier_id, "Date {$date} must be assigned to Cashier A");
            }

            // Verification: Wed, Thu are assigned to Cashier B
            foreach ([$wed, $thu] as $date) {
                $shift = CashierShift::where('shift_id', $this->template1->id)
                    ->whereDate('shift_date', $date)
                    ->first();
                $this->assertNotNull($shift, "Shift must exist for {$date}");
                $this->assertEquals($this->cashierB->id, $shift->cashier_id, "Date {$date} must be assigned to Cashier B");
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test inactive templates are skipped by renewal even if listed in roster.
     */
    public function test_inactive_template_is_skipped_even_with_roster(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));

        try {
            $inactiveTemplate = Shift::factory()->create([
                'branch_id' => $this->branch->id,
                'name' => 'Deactivated Template',
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
                'is_active' => false,
            ]);

            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $inactiveTemplate->id,
                'cashier_id' => $this->cashierA->id,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            $count = CashierShift::where('shift_id', $inactiveTemplate->id)->count();
            $this->assertSame(0, $count, 'Inactive template must never generate cashier shifts.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test fallback to current week pattern when no durable roster assignment exists.
     */
    public function test_fallback_to_current_week_pattern_when_no_roster(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $nextDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

            // Zero roster assignments exist in DB
            $this->assertSame(0, BranchShiftRosterAssignment::count());

            // Current week shift exists for Cashier A on Template 1
            CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $this->template1->id,
                'shift_date' => '2026-11-10',
                'status' => ShiftStatus::COMPLETED,
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            // Next week should have shifts generated for Cashier A on Template 1 via fallback
            $nextWeekCount = CashierShift::where('cashier_id', $this->cashierA->id)
                ->where('shift_id', $this->template1->id)
                ->whereDate('shift_date', '>=', $nextDates[0]->format('Y-m-d'))
                ->whereDate('shift_date', '<=', end($nextDates)->format('Y-m-d'))
                ->count();

            $this->assertSame(count($nextDates), $nextWeekCount, 'Fallback pattern must generate next week shifts.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * MT-23: Save recurring roster via authorized branch manager API endpoint, then execute renewal.
     */
    public function test_mt23_save_roster_via_api_and_renew_week_creates_expected_shifts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $nextDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

            // Call the authorized endpoint to save recurring roster assignments
            $payload = [
                'assignments' => [
                    [
                        'shift_id' => $this->template1->id,
                        'cashier_id' => $this->cashierA->id,
                        'effective_from' => '2026-11-01',
                    ],
                    [
                        'shift_id' => $this->template2->id,
                        'cashier_id' => $this->cashierB->id,
                        'effective_from' => '2026-11-01',
                    ],
                ],
            ];

            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/branch-manager/cashiers/roster-assignments', $payload);

            $response->assertOk()
                ->assertJsonPath('message', 'Roster assignments saved successfully');

            $this->assertDatabaseHas('branch_shift_roster_assignments', [
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
            ]);

            // Now execute renewal command
            $this->artisan('shifts:renew-week')->assertSuccessful();

            // Verify shifts were generated for each date according to the roster
            foreach ($nextDates as $date) {
                $dateStr = $date->format('Y-m-d');

                $t1Shift = CashierShift::where('shift_id', $this->template1->id)
                    ->whereDate('shift_date', $dateStr)
                    ->first();
                $this->assertNotNull($t1Shift, "Shift for template 1 must exist on {$dateStr}");
                $this->assertEquals($this->cashierA->id, $t1Shift->cashier_id);

                $t2Shift = CashierShift::where('shift_id', $this->template2->id)
                    ->whereDate('shift_date', $dateStr)
                    ->first();
                $this->assertNotNull($t2Shift, "Shift for template 2 must exist on {$dateStr}");
                $this->assertEquals($this->cashierB->id, $t2Shift->cashier_id);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * MT-23: Holidays are excluded from shift renewal.
     */
    public function test_mt23_holiday_is_excluded_from_renewal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $nextDatesAll = ShiftHelper::workWeekDateSequence($nextStart);

            // Set Tuesday (the 3rd work day: Sun=0, Mon=1, Tue=2) as holiday
            $holidayDate = $nextDatesAll[2]->format('Y-m-d');
            config(['shift.holidays' => [$holidayDate]]);

            $this->assertTrue(ShiftHelper::isHoliday($holidayDate), 'Date must be recognized as holiday.');

            // Setup roster assignment
            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
                'effective_from' => '2026-01-01',
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            // Verify ZERO shifts generated on the holiday date
            $holidayShifts = CashierShift::whereDate('shift_date', $holidayDate)->count();
            $this->assertSame(0, $holidayShifts, "Zero shifts must be generated on holiday {$holidayDate}");

            // Verify shifts WERE generated on non-holiday work days
            $nonHolidayDates = array_filter($nextDatesAll, fn ($d) => $d->format('Y-m-d') !== $holidayDate);
            foreach ($nonHolidayDates as $d) {
                $dStr = $d->format('Y-m-d');
                $count = CashierShift::whereDate('shift_date', $dStr)->count();
                $this->assertGreaterThan(0, $count, "Shifts must be generated on regular work day {$dStr}");
            }
        } finally {
            config(['shift.holidays' => []]);
            Carbon::setTestNow();
        }
    }

    /**
     * MT-23: Non-contiguous work days (e.g. Sunday, Tuesday, Thursday) are respected.
     */
    public function test_mt23_non_contiguous_work_days_are_respected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            // Configure non-contiguous work days: Sunday (0), Tuesday (2), Thursday (4)
            config(['shift.week.work_days' => [0, 2, 4]]);

            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
            $workDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

            // Sunday, Tuesday, Thursday = 3 dates
            $this->assertCount(3, $workDates);

            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template1->id,
                'cashier_id' => $this->cashierA->id,
                'effective_from' => '2026-01-01',
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            // Verify shifts only exist on work dates (0, 2, 4)
            $createdDates = CashierShift::where('shift_id', $this->template1->id)
                ->pluck('shift_date')
                ->map(fn ($d) => Carbon::parse($d)->dayOfWeek)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $this->assertEquals([0, 2, 4], $createdDates, 'Shifts must only be created on configured work days.');
        } finally {
            config(['shift.week.work_days' => [0, 1, 2, 3, 4]]);
            Carbon::setTestNow();
        }
    }

    /**
     * MT-23: Overnight shifts crossing midnight boundaries preserve hours and valid status.
     */
    public function test_mt23_overnight_shift_preserves_midnight_boundary_and_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));

        try {
            [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());

            // Template 2 is 20:00:00 - 04:00:00
            $this->assertEquals('20:00:00', Carbon::parse($this->template2->start_time)->format('H:i:s'));
            $this->assertEquals('04:00:00', Carbon::parse($this->template2->end_time)->format('H:i:s'));

            BranchShiftRosterAssignment::create([
                'branch_id' => $this->branch->id,
                'shift_id' => $this->template2->id,
                'cashier_id' => $this->cashierB->id,
                'effective_from' => '2026-01-01',
            ]);

            $this->artisan('shifts:renew-week')->assertSuccessful();

            $overnightShifts = CashierShift::where('shift_id', $this->template2->id)->get();
            $this->assertNotEmpty($overnightShifts);

            foreach ($overnightShifts as $cs) {
                $this->assertEquals(ShiftStatus::NOT_STARTED->value, $cs->status->value ?? $cs->status);
                $this->assertEquals($this->cashierB->id, $cs->cashier_id);
                // Template definition remains intact
                $this->assertEquals('20:00:00', Carbon::parse($cs->shift->start_time)->format('H:i:s'));
                $this->assertEquals('04:00:00', Carbon::parse($cs->shift->end_time)->format('H:i:s'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}
