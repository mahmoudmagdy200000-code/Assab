<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
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
}
