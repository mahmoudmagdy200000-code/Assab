<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftDailyCloseScopeService;
use Tests\TestCase;

/** Two real PDO connections; verifies current consumption reads under REPEATABLE READ. */
class ManagerWorkdayMySqlSnapshotTest extends TestCase
{
    public function test_committed_competing_consumption_is_seen_after_an_older_consistent_snapshot(): void
    {
        $config = DB::connection()->getConfig();
        if (env('PHASE2_MYSQL_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT_RUN: opt-in isolated MySQL 8.4 required.');
        }
        if ($config['host'] !== '127.0.0.1' || ! preg_match('/\As111_phase567_test_[a-z0-9_]+\z/', $config['database'])) {
            throw new \LogicException('Refusing any non-local or shared database.');
        }
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS v')->v);
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $day = BranchManagerShift::create(['branch_id' => $branch->id, 'branch_manager_id' => $manager->id,
            'shift_date' => '2026-10-29', 'status' => 'in_progress']);
        $otherDay = BranchManagerShift::create(['branch_id' => $branch->id, 'branch_manager_id' => $manager->id,
            'shift_date' => '2026-10-30', 'status' => 'completed']);
        $source = CashierShift::factory()->completed()->create(['shift_id' => $slot->id, 'cashier_id' => $cashier->id,
            'shift_date' => '2026-10-29']);
        config(['database.connections.phase2_competitor' => $config]);
        $competitor = DB::connection('phase2_competitor');
        $this->assertNotSame(DB::connection()->getPdo(), $competitor->getPdo());
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $scope = app(ShiftDailyCloseScopeService::class);
            $this->assertContains($source->id, $scope->resolve($day)['cashier_shift_ids']);
            $competitor->table('shift_liability_daily_locks')->insert(['id' => (string) Str::uuid(), 'cashier_shift_id' => $source->id,
                'branch_manager_shift_id' => $otherDay->id, 'report_revision' => '1', 'locked_by_id' => $manager->id, 'locked_at' => now()]);
            $this->assertContains($source->id, $scope->resolve($day)['cashier_shift_ids'], 'The old consistent snapshot still sees the pre-consumption input.');
            $this->assertNotContains($source->id, $scope->resolve($day, true)['cashier_shift_ids'], 'The command locking read must see the competing committed consumption.');
        } finally {
            DB::rollBack();
            DB::purge('phase2_competitor');
        }
    }

    public function test_app_noop_uses_current_locked_breakdown_after_an_older_snapshot(): void
    {
        $config = DB::connection()->getConfig();
        if (env('PHASE2_MYSQL_TESTS') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT_RUN: opt-in isolated MySQL 8.4 required.');
        }
        if ($config['host'] !== '127.0.0.1' || ! preg_match('/\As111_phase567_test_[a-z0-9_]+\z/', $config['database'])) {
            throw new \LogicException('Refusing any non-local or shared database.');
        }
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $day = BranchManagerShift::create(['branch_id' => $branch->id, 'branch_manager_id' => $manager->id,
            'shift_date' => '2026-10-29', 'status' => 'in_progress']);
        $source = CashierShift::factory()->completed()->create(['shift_id' => $slot->id, 'cashier_id' => $cashier->id,
            'shift_date' => '2026-10-29']);
        $app = \Modules\Aggregator\Models\Aggregator::factory()->create();
        $row = \Modules\Shift\Models\ShiftSalesBreakdown::create(['cashier_shift_id' => $source->id,
            'aggregator_id' => $app->id, 'amount' => '20.00']);
        config(['database.connections.phase2_competitor' => $config]);
        $competitor = DB::connection('phase2_competitor');
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame('20.00', (string) DB::table($row->getTable())->where('id', $row->id)->value('amount'));
            $competitor->table($row->getTable())->where('id', $row->id)->update(['amount' => '25.00']);
            $this->assertSame('20.00', (string) DB::table($row->getTable())->where('id', $row->id)->value('amount'));
            app(\Modules\Shift\Services\BranchManagerShiftService::class)->bulkUpdateCashierShifts([
                ['cashier_shift_id' => $source->id, 'delivery_app_payments' => '25.00'],
            ], $day, $manager);
            $this->assertSame(0, \Modules\Shift\Models\ShiftReportCorrection::whereHas('aggregate', fn ($q) => $q->where('source_id', $source->id))->count());
            $this->assertSame(0, \Modules\Shift\Models\ShiftReportAggregate::where('source_id', $source->id)->count());
        } finally {
            DB::rollBack();
            DB::purge('phase2_competitor');
        }
    }
}
