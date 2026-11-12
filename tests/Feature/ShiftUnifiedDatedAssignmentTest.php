<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

class ShiftUnifiedDatedAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Shift $template1;

    private Shift $template2;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze to a known Sunday morning in Riyadh
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(8, 0, 0));

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'name' => 'Cashier Alpha',
        ]);
        $this->cashierB = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'name' => 'Cashier Beta',
        ]);

        $this->template1 = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning Template',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);
        $this->template2 = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Evening Template',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'is_active' => true,
        ]);
    }

    /**
     * Prove that sequential calls to assign-shifts conflict when swapping cashiers.
     */
    public function test_proof_that_sequential_calls_conflict_when_swapping_two_cashiers(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        $date = '2026-11-15';

        // Initial assignment: Cashier A -> Template 1, Cashier B -> Template 2
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierB->id,
            'shift_ids' => [$this->template2->id],
            'shift_date' => $date,
        ])->assertOk();

        // Attempting to assign Template 2 to Cashier A via standard sequential call fails with 422
        // because Template 2 is currently assigned to Cashier B on that date.
        $responseA = $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template2->id],
            'shift_date' => $date,
        ]);

        $responseA->assertStatus(422);
        $responseA->assertJsonValidationErrors(['shift_ids']);

        // Attempting to assign Template 1 to Cashier B also fails with 422
        // because Template 1 is currently assigned to Cashier A on that date.
        $responseB = $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierB->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ]);

        $responseB->assertStatus(422);
        $responseB->assertJsonValidationErrors(['shift_ids']);
    }

    /**
     * MT-21: One/two shift templates, authorized manager assignment.
     * Cashier sees correct assignment; assignment does not start shift or confirm money.
     */
    public function test_mt21_manager_assigns_template_and_cashier_sees_it_without_starting_or_money(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $date = '2026-11-15';

        // 1. Manager assigns Template 1 to Cashier A
        $assignRes = $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $assignRes->assertJsonPath('data.shifts_assigned', 1);

        $shiftRow = CashierShift::where('cashier_id', $this->cashierA->id)
            ->where('shift_id', $this->template1->id)
            ->whereDate('shift_date', $date)
            ->firstOrFail();

        // Verify status and financial attributes are untouched
        $this->assertEquals(ShiftStatus::NOT_STARTED, $shiftRow->status);
        $this->assertNull($shiftRow->actual_start_time);
        $this->assertEquals('0.00', number_format((float) $shiftRow->opening_balance, 2, '.', ''));
        $this->assertEquals('0.00', number_format((float) $shiftRow->cash_collected, 2, '.', ''));
        $this->assertNull($shiftRow->pos_receipt);

        // 2. Cashier A queries their shifts and sees the assigned shift
        $this->actingAs($this->cashierA, 'sanctum');
        $cashierRes = $this->getJson('/api/v1/cashier/my-shifts')->assertOk();

        $shifts = $cashierRes->json('data');
        $matching = collect($shifts)->firstWhere('id', $shiftRow->id);
        $this->assertNotNull($matching, 'Cashier must see the assigned shift in their shifts list.');
        $this->assertEquals($shiftRow->id, $matching['id']);
        $this->assertEquals($this->template1->name, $matching['shift_name']);
        $this->assertEquals(ShiftStatus::NOT_STARTED->value, $matching['status']);
    }

    /**
     * MT-22: Swap cashiers succeeds atomically and preserves shift IDs.
     */
    public function test_mt22_atomic_swap_succeeds_and_preserves_shift_ids(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $date = '2026-11-15';

        // Setup: A -> Template 1, B -> Template 2
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierB->id,
            'shift_ids' => [$this->template2->id],
            'shift_date' => $date,
        ])->assertOk();

        $idA_before = CashierShift::where('cashier_id', $this->cashierA->id)->value('id');
        $idB_before = CashierShift::where('cashier_id', $this->cashierB->id)->value('id');

        // Swap via the atomic swap endpoint
        $swapRes = $this->postJson('/api/v1/branch-manager/cashiers/swap-shifts', [
            'cashier_a_id' => $this->cashierA->id,
            'shift_a_id' => $this->template1->id,
            'cashier_b_id' => $this->cashierB->id,
            'shift_b_id' => $this->template2->id,
            'shift_date' => $date,
        ]);

        $swapRes->assertOk();
        $swapRes->assertJsonPath('data.swapped', true);

        // Verification:
        // Cashier A now has Template 2, Cashier B now has Template 1
        $rowA_after = CashierShift::findOrFail($idA_before);
        $rowB_after = CashierShift::findOrFail($idB_before);

        $this->assertEquals($this->cashierA->id, $rowA_after->cashier_id);
        $this->assertEquals($this->template2->id, $rowA_after->shift_id, 'Cashier A must now be assigned to Template 2');
        $this->assertEquals($idA_before, $rowA_after->id, 'Assignment ID A must be preserved');

        $this->assertEquals($this->cashierB->id, $rowB_after->cashier_id);
        $this->assertEquals($this->template1->id, $rowB_after->shift_id, 'Cashier B must now be assigned to Template 1');
        $this->assertEquals($idB_before, $rowB_after->id, 'Assignment ID B must be preserved');

        // Total shifts count remains 2 (no duplicate rows created or deleted)
        $this->assertDatabaseCount('cashier_shifts', 2);
    }

    /**
     * MT-22: Swap fails atomically if one shift has started or has financial evidence,
     * preserving both assignments, IDs, and financial state exactly as they were.
     */
    public function test_mt22_swap_fails_atomically_if_one_shift_has_financial_evidence(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $date = '2026-11-15';

        // Setup: A -> Template 1, B -> Template 2
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierB->id,
            'shift_ids' => [$this->template2->id],
            'shift_date' => $date,
        ])->assertOk();

        $rowA = CashierShift::where('cashier_id', $this->cashierA->id)->firstOrFail();
        $rowB = CashierShift::where('cashier_id', $this->cashierB->id)->firstOrFail();

        // Simulate financial evidence on Cashier A's shift
        $rowA->update(['opening_balance' => '50.00']);

        $beforeStateA = $rowA->fresh()->getRawOriginal();
        $beforeStateB = $rowB->fresh()->getRawOriginal();

        // Attempt swap
        $swapRes = $this->postJson('/api/v1/branch-manager/cashiers/swap-shifts', [
            'cashier_a_id' => $this->cashierA->id,
            'shift_a_id' => $this->template1->id,
            'cashier_b_id' => $this->cashierB->id,
            'shift_b_id' => $this->template2->id,
            'shift_date' => $date,
        ]);

        $swapRes->assertStatus(422);

        // Verification of atomicity: neither row is modified
        $this->assertSame($beforeStateA, $rowA->fresh()->getRawOriginal(), 'Protected shift A must remain unchanged.');
        $this->assertSame($beforeStateB, $rowB->fresh()->getRawOriginal(), 'Shift B must remain unchanged when swap is rejected.');
    }

    /**
     * MT-22: Midweek assignment change preserves worked history and shift IDs.
     */
    public function test_mt22_midweek_change_preserves_worked_history_and_ids(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        // Cashier A was assigned for the week starting Sunday Nov 15
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => '2026-11-15',
        ])->assertOk();

        // Simulate worked days: Sunday Nov 15 and Monday Nov 16 were completed
        $sundayRow = CashierShift::where('cashier_id', $this->cashierA->id)
            ->whereDate('shift_date', '2026-11-15')
            ->firstOrFail();
        $sundayRow->update([
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => '115.00',
            'net_sales' => '100.00',
            'vat_amount' => '15.00',
        ]);
        $sundayOriginal = $sundayRow->fresh()->getRawOriginal();

        // On Tuesday Nov 17 (midweek), manager assigns Template 1 to Cashier B for Tuesday
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(8, 0, 0));

        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierB->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => '2026-11-17',
        ])->assertOk();

        // Sunday worked history is preserved
        $this->assertSame($sundayOriginal, CashierShift::findOrFail($sundayRow->id)->getRawOriginal());

        // Tuesday row has Cashier B assigned
        $tuesdayRow = CashierShift::where('cashier_id', $this->cashierB->id)
            ->whereDate('shift_date', '2026-11-17')
            ->firstOrFail();
        $this->assertEquals($this->template1->id, $tuesdayRow->shift_id);
    }

    /**
     * MT-24: Start tomorrow's shift is permitted while yesterday's shift awaits review.
     */
    public function test_mt24_start_shift_tomorrow_permitted_when_yesterday_awaits_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 08:30:00'));
        try {
            // 1. Yesterday (Nov 14), Cashier A had a shift that ended and has report submitted / pending review
            $yesterday = '2026-11-14';
            $yesterdayShift = CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $this->template1->id,
                'shift_date' => $yesterday,
                'status' => ShiftStatus::COMPLETED,
                'actual_start_time' => Carbon::parse("{$yesterday} 08:00:00"),
                'actual_end_time' => Carbon::parse("{$yesterday} 16:00:00"),
                'review_status' => 'pending', // Awaiting manager/accountant review
            ]);

            // 2. Today (Nov 15), Cashier A has a scheduled shift
            $today = '2026-11-15';
            $todayShift = CashierShift::factory()->create([
                'cashier_id' => $this->cashierA->id,
                'shift_id' => $this->template1->id,
                'shift_date' => $today,
                'status' => ShiftStatus::NOT_STARTED,
                'opening_balance' => '0.00',
            ]);

            // 3. Cashier starts today's shift
            $this->actingAs($this->cashierA, 'sanctum');
            $startRes = $this->postJson("/api/v1/cashier/shifts/{$todayShift->id}/start");

            $startRes->assertOk();
            $startRes->assertJsonPath('data.shift.status', ShiftStatus::IN_PROGRESS->value);

            // Verification: today's shift is now in progress and yesterday's report remains pending review
            $this->assertEquals(ShiftStatus::IN_PROGRESS, $todayShift->fresh()->status);
            $this->assertNotNull($todayShift->fresh()->actual_start_time);
            $this->assertEquals('pending', $yesterdayShift->fresh()->review_status);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test dated-roster endpoint returns templates and cashier assignments for a date range.
     */
    public function test_dated_roster_endpoint_returns_templates_and_cashiers(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $date = '2026-11-15';

        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $rosterRes = $this->getJson("/api/v1/branch-manager/cashiers/dated-roster?start_date={$date}&end_date={$date}");

        $rosterRes->assertOk();
        $rosterRes->assertJsonPath('data.branch_id', $this->branch->id);
        $rosterRes->assertJsonCount(2, 'data.templates');

        $key = "{$date}_{$this->template1->id}";
        $this->assertEquals($this->cashierA->id, $rosterRes->json("data.roster.{$key}.cashier_id"));
        $this->assertEquals('Cashier Alpha', $rosterRes->json("data.roster.{$key}.cashier_name"));
        $this->assertTrue($rosterRes->json("data.roster.{$key}.can_swap"));
    }

    /**
     * Test available-cashiers endpoint contract: distinguishes unassigned template_id vs existing shift_id.
     */
    public function test_available_cashiers_contract_template_vs_existing_shift(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $date = '2026-11-15';

        // Assign Cashier A to template 1
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
            'cashier_id' => $this->cashierA->id,
            'shift_ids' => [$this->template1->id],
            'shift_date' => $date,
        ])->assertOk();

        $existingCashierShift = CashierShift::where('cashier_id', $this->cashierA->id)
            ->where('shift_id', $this->template1->id)
            ->whereDate('shift_date', $date)
            ->firstOrFail();

        // 1. Query available cashiers for unassigned template slot using template_id
        $resTemplate = $this->getJson("/api/v1/branch-manager/cashiers/available-cashiers?template_id={$this->template1->id}&shift_date={$date}");
        $resTemplate->assertOk();
        $cashiers = collect($resTemplate->json('data'));
        $cashierAEntry = $cashiers->firstWhere('id', $this->cashierA->id);
        $cashierBEntry = $cashiers->firstWhere('id', $this->cashierB->id);
        // Cashier A is already working on template 1 on this date -> disabled/unavailable
        $this->assertFalse($cashierAEntry['is_available']);
        $this->assertTrue($cashierAEntry['disabled']);
        // Cashier B is free
        $this->assertTrue($cashierBEntry['is_available']);
        $this->assertFalse($cashierBEntry['disabled']);

        // 2. Query available cashiers for existing CashierShift using shift_id
        $resShift = $this->getJson("/api/v1/branch-manager/cashiers/available-cashiers?shift_id={$existingCashierShift->id}&shift_date={$date}");
        $resShift->assertOk();
        $cashiersShift = collect($resShift->json('data'));
        $cashierAInShift = $cashiersShift->firstWhere('id', $this->cashierA->id);
        // Current cashier for this shift is not marked busy because id != $shiftId
        $this->assertTrue($cashierAInShift['is_available']);
    }
}
