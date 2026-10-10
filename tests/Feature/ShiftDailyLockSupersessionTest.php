<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Liability\DailyLiabilityGuard;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Tests\TestCase;

class ShiftDailyLockSupersessionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Shift $template;

    private CashierShift $cashierShift;

    private BranchManagerShift $workday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->template = Shift::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
        ]);

        $this->workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
    }

    public function test_schema_has_additive_reopen_and_superseded_columns(): void
    {
        $columns = Schema::getColumnListing('shift_liability_daily_locks');

        $this->assertContains('superseded_at', $columns);
        $this->assertContains('reopened_at', $columns);
        $this->assertContains('reopened_by_type', $columns);
        $this->assertContains('reopened_by_id', $columns);
        $this->assertContains('reopen_reason', $columns);

        // Legacy columns must remain intact
        $this->assertContains('released_at', $columns);
        $this->assertContains('released_by_id', $columns);
        $this->assertContains('release_reason', $columns);
    }

    public function test_scope_active_filters_both_released_and_superseded_locks(): void
    {
        // 1. Active lock
        $active = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        // 2. Released lock
        $released = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
            'released_at' => now(),
            'released_by_id' => $this->manager->id,
            'release_reason' => 'Released normally',
        ]);

        // 3. Superseded lock
        $superseded = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
            'superseded_at' => now(),
            'reopened_at' => now(),
            'reopened_by_type' => 'branch_manager',
            'reopened_by_id' => $this->manager->id,
            'reopen_reason' => 'Superseded by new revision',
        ]);

        $activeIds = ShiftLiabilityDailyLock::active()->pluck('id')->all();

        $this->assertContains($active->id, $activeIds);
        $this->assertNotContains($released->id, $activeIds);
        $this->assertNotContains($superseded->id, $activeIds);
    }

    public function test_release_day_updates_both_legacy_and_additive_metadata(): void
    {
        $lock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        $guard = app(DailyLiabilityGuard::class);
        $affected = DB::transaction(function () use ($guard) {
            return $guard->releaseDay($this->workday->id, $this->manager, 'Authorized audit correction');
        });

        $this->assertSame(1, $affected);

        $fresh = $lock->fresh();
        $this->assertNotNull($fresh->released_at);
        $this->assertSame((string) $this->manager->id, (string) $fresh->released_by_id);
        $this->assertSame('Authorized audit correction', $fresh->release_reason);
        $this->assertNotNull($fresh->reopened_at);
        $this->assertSame('branch_manager', $fresh->reopened_by_type);
        $this->assertSame((string) $this->manager->id, (string) $fresh->reopened_by_id);
        $this->assertSame('Authorized audit correction', $fresh->reopen_reason);

        // Idempotent retry: calling releaseDay again does not update already-released lock
        $firstReleasedAt = $fresh->released_at;
        $affectedRetry = DB::transaction(function () use ($guard) {
            return $guard->releaseDay($this->workday->id, $this->manager, 'Another attempt');
        });

        $this->assertSame(0, $affectedRetry);
        $this->assertEquals($firstReleasedAt->toDateTimeString(), $lock->fresh()->released_at->toDateTimeString());
    }

    public function test_supersede_locks_for_shift_updates_supersession_metadata(): void
    {
        $lock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        $guard = app(DailyLiabilityGuard::class);
        $affected = DB::transaction(function () use ($guard) {
            return $guard->supersedeLocksForShift($this->cashierShift->id, $this->manager, 'Superseded for recount');
        });

        $this->assertSame(1, $affected);

        $fresh = $lock->fresh();
        $this->assertNotNull($fresh->superseded_at);
        $this->assertNotNull($fresh->reopened_at);
        $this->assertSame('branch_manager', $fresh->reopened_by_type);
        $this->assertSame((string) $this->manager->id, (string) $fresh->reopened_by_id);
        $this->assertSame('Superseded for recount', $fresh->reopen_reason);
        $this->assertNull($fresh->released_at);

        $this->assertFalse(ShiftLiabilityDailyLock::active()->where('id', $lock->id)->exists());
    }

    public function test_carry_over_shift_locked_in_two_workdays_stays_locked_when_only_one_released(): void
    {
        $workday2 = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today()->addDay(),
            'status' => 'completed',
        ]);

        $lock1 = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        $lock2 = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $workday2->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        $guard = app(DailyLiabilityGuard::class);
        DB::transaction(function () use ($guard) {
            $guard->releaseDay($this->workday->id, $this->manager, 'Release first day');
        });

        $this->assertNotNull($lock1->fresh()->released_at);
        $this->assertNull($lock2->fresh()->released_at);

        // Cashier shift must still have an active lock due to workday 2
        $this->assertTrue(ShiftLiabilityDailyLock::active()->where('cashier_shift_id', $this->cashierShift->id)->exists());
    }

    public function test_gate_d_pr_blocks_http_endpoint_and_leaves_locks_unchanged(): void
    {
        $this->workday->update([
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
            'can_reopen' => true,
        ]);

        $lock = ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/branch-manager/workday/daily-close/reopen', [
                'reopen_reason' => 'Unauthorized external reopen attempt',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_REOPEN_REQUIRED');

        $fresh = $lock->fresh();
        $this->assertNull($fresh->released_at);
        $this->assertNull($fresh->superseded_at);
        $this->assertTrue($this->workday->fresh()->daily_report_submitted);
    }

    public function test_migration_down_refuses_rollback_if_superseded_history_exists(): void
    {
        ShiftLiabilityDailyLock::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'branch_manager_shift_id' => $this->workday->id,
            'report_revision' => '1',
            'locked_by_id' => $this->manager->id,
            'locked_at' => now(),
            'superseded_at' => now(),
        ]);

        $migration = require base_path('Modules/Shift/database/migrations/2026_10_10_000005_add_reopen_metadata_to_daily_liability_locks.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot rollback migration: shift_liability_daily_locks contains superseded history.');

        $migration->down();
    }
}
