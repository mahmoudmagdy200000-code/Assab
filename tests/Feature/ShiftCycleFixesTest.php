<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Services\ShiftScheduleBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftService;
use Tests\TestCase;

/**
 * The accountant → branch-manager → cashier shift cycle (review 2026-08-10).
 *
 * Four seams that were broken end to end:
 *  1. «الرصيد الافتتاحي» set on the dashboard never reached a mobile shift;
 *  2. a pending shift could be reassigned to a cashier of ANOTHER branch;
 *  3. a reassigned shift disappeared from the receiving cashier's Pending tab;
 *  4. «Next Cashier» skipped a reassigned shift and fell through to the manager.
 */
class ShiftCycleFixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['name' => 'فرع السعادة']);
        $this->otherBranch = Branch::factory()->create(['name' => 'فرع آخر']);
    }

    private function template(Branch $branch, string $start, string $end, float $float = 0): Shift
    {
        return Shift::factory()->create([
            'branch_id' => $branch->id, 'name' => "{$start}-{$end}",
            'start_time' => $start, 'end_time' => $end,
            'is_active' => true, 'opening_float' => $float,
        ]);
    }

    // ── 1. the opening float ─────────────────────────────────────────────────

    public function test_the_dashboards_opening_float_reaches_a_new_cashier_shift(): void
    {
        $company = AsabCompany::create(['name' => 'Float Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch->forceFill(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id])->save();

        BrandShiftConfig::create([
            'brand_id' => $brand->id, 'num_shifts' => 2, 'duration_hours' => 6, 'duration_minutes' => 360,
            'first_shift_start' => '06:00', 'shifts' => ['openingFloatHalalas' => 30000],
        ]);
        app(ShiftScheduleBridgeService::class)->regenerateForBrand($brand->id);

        $template = Shift::where('branch_id', $this->branch->id)->where('start_time', '06:00:00')->first();
        $this->assertNotNull($template, 'the regenerate bridge must seed the branch template');
        $this->assertSame('300.00', (string) $template->opening_float);

        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);

        $this->assertSame('300.00', (string) $shift->fresh()->opening_balance);
    }

    public function test_a_schedule_without_a_float_still_opens_at_zero(): void
    {
        $template = $this->template($this->branch, '06:00:00', '12:00:00');
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);

        $this->assertSame(0.0, (float) $shift->fresh()->opening_balance);
    }

    // ── 2. reassignment stays inside the branch ──────────────────────────────

    public function test_a_pending_shift_cannot_be_reassigned_to_another_branchs_cashier(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $template = $this->template($this->branch, '06:00:00', '12:00:00');
        $mine = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $outsider = Cashier::factory()->create(['branch_id' => $this->otherBranch->id]);

        $shift = CashierShift::create([
            'cashier_id' => $mine->id, 'shift_id' => $template->id,
            'shift_date' => today()->addDay()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/reassign", [
                'new_cashier_id' => $outsider->id, 'reason' => 'test',
            ])
            ->assertStatus(403);

        $this->assertSame($mine->id, $shift->fresh()->cashier_id);
    }

    public function test_a_pending_shift_is_reassigned_within_the_branch(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $template = $this->template($this->branch, '06:00:00', '12:00:00');
        $mine = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $colleague = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = CashierShift::create([
            'cashier_id' => $mine->id, 'shift_id' => $template->id,
            'shift_date' => today()->addDay()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$shift->id}/reassign", [
                'new_cashier_id' => $colleague->id, 'reason' => 'إجازة',
            ])
            ->assertOk();

        $this->assertSame($colleague->id, $shift->fresh()->cashier_id);
        $this->assertSame(ShiftStatus::REASSIGNED, $shift->fresh()->status);
    }

    // ── 3. the receiving cashier still sees it as pending ────────────────────

    public function test_a_reassigned_shift_stays_in_the_receiving_cashiers_pending_list(): void
    {
        $template = $this->template($this->branch, '06:00:00', '12:00:00');
        $colleague = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = CashierShift::create([
            'cashier_id' => $colleague->id, 'shift_id' => $template->id,
            'shift_date' => today()->addDay()->toDateString(), 'status' => ShiftStatus::REASSIGNED->value,
        ]);

        $rows = $this->actingAs($colleague, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')
            ->assertOk()->json('data');

        $this->assertSame([$shift->id], collect($rows)->pluck('id')->all());
        $this->assertSame('Reassigned', collect($rows)->first()['status_label']);
    }

    // ── 4. next cashier ──────────────────────────────────────────────────────

    public function test_next_cashier_still_resolves_when_the_following_shift_was_reassigned(): void
    {
        $morning = $this->template($this->branch, '06:00:00', '12:00:00');
        $evening = $this->template($this->branch, '12:00:00', '18:00:00');
        $first = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $second = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $date = today()->toDateString();

        $shiftOne = CashierShift::create([
            'cashier_id' => $first->id, 'shift_id' => $morning->id,
            'shift_date' => $date, 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        CashierShift::create([
            'cashier_id' => $second->id, 'shift_id' => $evening->id,
            'shift_date' => $date, 'status' => ShiftStatus::REASSIGNED->value,
        ]);

        $next = app(ShiftService::class)->getNextShiftCashier($shiftOne->fresh());

        $this->assertNotNull($next, 'a reassigned next shift is still the next shift');
        $this->assertSame($second->id, $next->id);
    }
}
