<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

class ShiftNotWorkedHttpTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $shift;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \LogicException('PKG-7 functional tests require disposable SQLite :memory:.');
        }
        parent::setUp();
        $this->travelTo(today()->setTime(15, 0));
        $branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '08:00:00', 'end_time' => '14:00:00']);
        $this->shift = CashierShift::factory()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $slot->id, 'shift_date' => today()]);
    }

    private function url(): string
    {
        return "/api/v1/branch-manager/shifts/{$this->shift->id}/mark-not-worked";
    }

    public function test_success_and_identical_key_replay_are_exactly_once(): void
    {
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->postJson($this->url(), ['reason' => 'Closed']);
        $first->assertOk()->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.not_worked.reason', 'Closed');
        $retry = $this->postJson($this->url(), ['reason' => 'Closed']);
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
        $this->postJson($this->url(), ['reason' => 'Different'])->assertConflict();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url(), ['reason' => 'Closed'])->assertConflict();
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
    }

    public function test_missing_key_and_blank_reason_have_no_effect(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $this->postJson($this->url(), ['reason' => 'Closed'])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url(), ['reason' => " \t\n "])->assertUnprocessable();
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_cashier_cannot_execute_manager_command(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertForbidden();
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);
    }

    public function test_other_branch_manager_cannot_access_shift(): void
    {
        $other = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($other, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertNotFound();
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_inactive_manager_is_rejected_before_replay(): void
    {
        $this->manager->update(['is_active' => false]);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url(), ['reason' => 'Closed'])->assertUnauthorized();
    }

    public function test_suspended_manager_is_rejected(): void
    {
        // Existing schema only persists active/inactive; exercise the existing middleware
        // suspension branch with an authenticated in-memory suspended principal.
        $this->manager->forceFill(['status' => 'suspended']);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertForbidden();
    }

    public function test_long_reason_and_unknown_uuid_have_no_effect(): void
    {
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => str_repeat('x', 501)])->assertUnprocessable();
        $this->postJson('/api/v1/branch-manager/shifts/'.Str::uuid().'/mark-not-worked', ['reason' => 'Closed'])->assertNotFound();
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_marked_shift_cannot_start_or_be_reassigned(): void
    {
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertOk();
        $replacement = Cashier::factory()->create(['branch_id' => $this->cashier->branch_id, 'created_by' => $this->manager->id]);
        $this->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/reassign", ['new_cashier_id' => $replacement->id])->assertStatus(400);
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/start")->assertStatus(400);
        $this->assertSame(ShiftStatus::CANCELED, $this->shift->fresh()->status);
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->count());
    }

    private function administrativeReplacement(): Cashier
    {
        $replacement = Cashier::factory()->create(['branch_id' => $this->cashier->branch_id, 'created_by' => $this->manager->id]);
        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$this->shift->id}/reassign",
            ['new_cashier_id' => $replacement->id, 'reason' => 'Administrative replacement']
        )->assertOk();
        $this->actingAs($replacement, 'sanctum')->postJson(
            "/api/v1/cashier/shifts/{$this->shift->id}/reassign/accept"
        )->assertOk();
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);

        return $replacement;
    }

    public function test_never_worked_accepted_replacement_remains_eligible_and_keeps_history(): void
    {
        $this->administrativeReplacement();
        $before = $this->shift->history()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertOk();
        $after = $this->shift->history()->where('action', '!=', 'marked_not_worked')->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertSame($before, $after);
        $this->assertSame(0, DB::table('shift_report_aggregates')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
    }

    public function test_missing_acceptance_after_reassignment_fails_closed_without_mutation(): void
    {
        $this->administrativeReplacement();
        $this->shift->history()->where('action', 'reassigned_shift_accepted_without_handover')->delete();
        $before = $this->shift->fresh()->getRawOriginal();
        $history = $this->shift->history()->count();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_REASSIGNMENT_AMBIGUOUS');
        $this->assertSame($before, $this->shift->fresh()->getRawOriginal());
        $this->assertSame($history, $this->shift->history()->count());
    }

    public function test_prior_work_history_cannot_be_washed_by_resetting_replacement_to_pending(): void
    {
        $replacement = $this->administrativeReplacement();
        $this->actingAs($replacement, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/start")->assertOk();
        // Corruption fixture resets fields; immutable prior started history still proves activity.
        DB::table('cashier_shifts')->where('id', $this->shift->id)->update([
            'status' => 'not_started', 'actual_start_time' => null, 'operational_chain_id' => null,
        ]);
        $before = $this->shift->fresh()->getRawOriginal();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_HAS_EVIDENCE');
        $this->assertSame($before, $this->shift->fresh()->getRawOriginal());
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_future_dated_assignment_is_rejected_explicitly(): void
    {
        DB::table('cashier_shifts')->where('id', $this->shift->id)->update(['shift_date' => today()->addDays(30)->toDateString()]);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Scheduled closure'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_FUTURE_ASSIGNMENT');
        $this->assertTrue($this->shift->fresh()->shift_date->isFuture());
        $this->assertNull($this->shift->fresh()->actual_start_time);
        $this->assertSame(0, DB::table('shift_report_revisions')->count());
    }

    public function test_assignment_cannot_be_marked_before_its_scheduled_period_ends(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(12, 0));
        $this->shift->update(['shift_date' => today(), 'status' => ShiftStatus::NOT_STARTED]);
        $this->shift->shift()->update(['start_time' => '13:00:00', 'end_time' => '14:00:00']);

        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_PERIOD_OPEN');
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());
    }

    public function test_overnight_assignment_stays_open_until_the_next_morning(): void
    {
        $workDate = today();
        $this->shift->update(['shift_date' => $workDate->copy()->subDay(), 'status' => ShiftStatus::NOT_STARTED]);
        $this->shift->shift()->update(['start_time' => '22:00:00', 'end_time' => '06:00:00']);

        $this->travelTo($workDate->copy()->setTime(2, 0));
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Night shift still open'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_PERIOD_OPEN');
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->shift->fresh()->status);
        $this->assertSame(0, $this->shift->notWorkedHistory()->count());

        $this->travelTo($workDate->copy()->setTime(6, 1));
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Night shift ended'])->assertOk();

        $this->assertSame(ShiftStatus::CANCELED, $this->shift->fresh()->status);
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->where('related_shift_id', $this->shift->id)->count());
        $this->assertSame(0, DB::table('personal_ledger_transactions')->where('related_shift_id', $this->shift->id)->count());
    }

    public function test_no_work_is_scoped_to_one_cashier_assignment_when_replacement_is_active(): void
    {
        $replacement = Cashier::factory()->create(['branch_id' => $this->cashier->branch_id, 'created_by' => $this->manager->id]);
        $replacementShift = CashierShift::factory()->create([
            'cashier_id' => $replacement->id, 'shift_id' => $this->shift->shift_id,
            'shift_date' => $this->shift->shift_date, 'status' => ShiftStatus::NOT_STARTED,
        ]);

        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Assigned cashier absent'])->assertOk();

        $this->assertSame(ShiftStatus::CANCELED, $this->shift->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $replacementShift->fresh()->status);
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
        $this->assertSame(0, $replacementShift->notWorkedHistory()->count());
    }

    public function test_acceptance_system_label_does_not_allow_a_different_actor(): void
    {
        $this->administrativeReplacement();
        $this->shift->history()->where('action', 'reassigned_shift_accepted_without_handover')->update(['performed_by' => $this->manager->id]);
        $before = $this->shift->history()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertConflict()
            ->assertJsonPath('code', 'SHIFT_NOT_WORKED_REASSIGNMENT_AMBIGUOUS');
        $this->assertSame($before, $this->shift->history()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_multiple_administrative_replacements_before_work_remain_eligible(): void
    {
        $this->administrativeReplacement();
        $this->administrativeReplacement();
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->url(), ['reason' => 'Closed'])->assertOk();
        $this->assertSame(2, $this->shift->history()->where('action', 'reassigned')->count());
        $this->assertSame(2, $this->shift->history()->where('action', 'reassigned_shift_accepted_without_handover')->count());
        $this->assertSame(1, $this->shift->notWorkedHistory()->count());
    }
}
