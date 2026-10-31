<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

class CashierRosterRepairTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Shift $first;

    private Shift $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 5));
        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->first = Shift::factory()->create(['branch_id' => $this->branch->id, 'start_time' => '12:00:00', 'end_time' => '20:00:00']);
        $this->second = Shift::factory()->create(['branch_id' => $this->branch->id, 'start_time' => '20:00:00', 'end_time' => '04:00:00']);
        $this->actingAs($this->manager, 'sanctum');
    }

    private function roster(array $ids): void
    {
        app(CashierService::class)->updateCashierShifts($this->cashier->id, $ids);
    }

    private function update(array $payload)
    {
        $response = $this->putJson('/api/v1/branch-manager/cashiers/'.$this->cashier->id, $payload);

        return $response;
    }

    private function rows(): string
    {
        return DB::table('cashier_shifts')->orderBy('id')->get()->toJson();
    }

    public function test_repeated_selection_preserves_exact_existing_rows(): void
    {
        $this->roster([$this->first->id]);
        $before = $this->rows();
        $this->update(['shift_ids' => [$this->first->id]])->assertOk();
        $this->assertSame($before, $this->rows());
        $this->assertDatabaseCount('cashier_shifts', 5);
    }

    public function test_add_then_subset_removal_keeps_retained_ids_and_history(): void
    {
        $this->roster([$this->first->id]);
        $ids = CashierShift::where('shift_id', $this->first->id)->pluck('id')->all();
        $history = CashierShift::factory()->completed()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $this->second->id, 'shift_date' => today()->subDay()]);
        $before = $history->fresh()->getRawOriginal();
        $this->update(['shift_ids' => [$this->first->id, $this->second->id]])->assertOk();
        $this->assertDatabaseCount('cashier_shifts', 11);
        $this->update(['shift_ids' => [$this->first->id]])->assertOk();
        $this->assertEqualsCanonicalizing($ids, CashierShift::where('shift_id', $this->first->id)->pluck('id')->all());
        $this->assertSame($before, $history->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cashier_shifts', 6);
    }

    public function test_financial_evidence_blocks_removal_and_rolls_back_profile_and_new_rows(): void
    {
        $this->roster([$this->first->id]);
        CashierShift::where('shift_id', $this->first->id)->firstOrFail()->update(['opening_balance' => '0.01']);
        $before = $this->rows();
        $name = $this->cashier->name;
        $this->update(['name' => 'Must roll back', 'shift_ids' => [$this->second->id]])->assertStatus(422);
        $this->assertSame($before, $this->rows());
        $this->assertSame($name, $this->cashier->fresh()->name);
    }

    public function test_invalid_foreign_or_inactive_selection_is_atomic(): void
    {
        $this->roster([$this->first->id]);
        $foreign = Shift::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $inactive = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);
        $before = $this->rows();
        foreach ([$foreign->id, $inactive->id] as $id) {
            $this->update(['name' => 'Must roll back', 'shift_ids' => [$this->first->id, $id]])->assertStatus(422);
            $this->assertSame($before, $this->rows());
        }
        $this->assertSame($this->cashier->name, $this->cashier->fresh()->name);
    }

    public function test_empty_and_duplicate_selections_are_rejected_without_mutation(): void
    {
        $this->roster([$this->first->id]);
        $before = $this->rows();
        foreach ([[], [$this->first->id, $this->first->id]] as $ids) {
            $this->update(['shift_ids' => $ids])->assertStatus(422);
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_branch_move_is_denied_before_any_profile_write(): void
    {
        $this->update(['name' => 'Must not change', 'store_branch_id' => Branch::factory()->create()->id])->assertForbidden();
        $this->assertSame($this->cashier->name, $this->cashier->fresh()->name);
        $this->assertSame($this->branch->id, $this->cashier->fresh()->branch_id);
    }

    public function test_current_schedule_excludes_inactive_and_completed_history_but_keeps_history_readable(): void
    {
        $old = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);
        CashierShift::factory()->completed()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $old->id, 'shift_date' => today()->subDays(8)]);
        $this->roster([$this->second->id, $this->first->id]);
        $response = $this->getJson('/api/v1/branch-manager/cashiers/'.$this->cashier->id)->assertOk();
        $this->assertSame([$this->first->id, $this->second->id], array_column($response->json('data.cashier.working_shifts'), 'id'));
        $this->assertCount(1, $response->json('data.shifts.completed'));
        $list = $this->getJson('/api/v1/branch-manager/cashiers')->assertOk();
        $this->assertSame([$this->first->id, $this->second->id], array_column($list->json('data.0.working_shifts'), 'id'));
        $list->assertJsonPath('data.0.number_of_shifts_per_day', 2);
    }

    public function test_absent_current_assignments_are_empty_and_never_inferred_from_new_templates(): void
    {
        $this->getJson('/api/v1/branch-manager/cashiers/'.$this->cashier->id)->assertOk()->assertJsonPath('data.cashier.working_shifts', []);
        $this->assertDatabaseCount('cashier_shifts', 0);
    }

    public function test_uuid_week_endpoint_works_and_foreign_cashier_is_hidden(): void
    {
        $this->roster([$this->first->id]);
        $this->getJson('/api/v1/branch-manager/cashiers/'.$this->cashier->id.'/shifts')->assertOk();
        $foreign = Cashier::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/branch-manager/cashiers/'.$foreign->id.'/shifts')->assertNotFound();
        $this->getJson('/api/v1/branch-manager/cashiers/not-a-uuid/shifts')->assertNotFound();
    }

    public function test_overnight_in_progress_remains_visible_after_midnight(): void
    {
        CashierShift::factory()->inProgress()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $this->second->id, 'shift_date' => today()->subDay()]);
        $this->getJson('/api/v1/branch-manager/cashiers/'.$this->cashier->id)->assertOk()->assertJsonCount(1, 'data.shifts.in_progress');
    }

    public function test_other_cashier_occupation_blocks_service_writer_as_well_as_controller(): void
    {
        $other = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        CashierShift::factory()->create(['cashier_id' => $other->id, 'shift_id' => $this->second->id, 'shift_date' => today()]);
        $before = $this->rows();
        try {
            $this->roster([$this->first->id, $this->second->id]);
            $this->fail('Occupied selection must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('shift_ids', $e->errors());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_edit_does_not_remove_started_completed_or_reassigned_rows(): void
    {
        $this->roster([$this->first->id]);
        $rows = CashierShift::where('shift_id', $this->first->id)->orderBy('shift_date')->get();
        foreach ([ShiftStatus::IN_PROGRESS, ShiftStatus::COMPLETED, ShiftStatus::REASSIGNED] as $i => $status) {
            $rows[$i]->update(['status' => $status, 'total_sales' => '115.00', 'net_sales' => '100.00', 'vat_amount' => '15.00']);
        }
        $preserved = $rows->take(3)->map(fn ($row) => $row->fresh()->getRawOriginal())->all();
        $this->update(['shift_ids' => [$this->second->id]])->assertOk();
        foreach ($preserved as $attributes) {
            $this->assertSame($attributes, CashierShift::findOrFail($attributes['id'])->getRawOriginal());
        }
    }

    public function test_existing_assignment_audit_is_not_deleted_by_roster_edit(): void
    {
        $this->roster([$this->first->id]);
        $row = CashierShift::where('shift_id', $this->first->id)->firstOrFail();
        $row->recordHistory('assigned', null, ['cashier_id' => $this->cashier->id]);
        $before = $this->rows();
        $this->update(['shift_ids' => [$this->second->id]])->assertStatus(422);
        $this->assertSame($before, $this->rows());
        $this->assertDatabaseHas('cashier_shift_history', ['cashier_shift_id' => $row->id, 'action' => 'assigned']);
    }

    public function test_weekly_renewal_skips_inactive_history_without_inventing_replacements(): void
    {
        $inactive = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);
        CashierShift::factory()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $inactive->id, 'shift_date' => today()]);
        $this->artisan('shifts:renew-week')->assertSuccessful();
        $this->assertDatabaseCount('cashier_shifts', 1);
        $this->assertSame(0, CashierShift::whereIn('shift_id', [$this->first->id, $this->second->id])->count());
    }
}
