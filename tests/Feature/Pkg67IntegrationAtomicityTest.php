<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftNotWorkedService;
use Modules\Shift\Services\ShiftReportCacheInvalidator;
use Tests\TestCase;

/** PKG-7 audit evidence and the status change must commit together or not at all (composed tree). */
class Pkg67IntegrationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_after_audit_write_rolls_back_status_and_history(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id]);
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $slot->id, 'shift_date' => today()]);
        $before = $shift->fresh()->getRawOriginal();
        $level = DB::transactionLevel(); // RefreshDatabase already holds the outer test transaction.

        $failing = \Mockery::mock(ShiftReportCacheInvalidator::class);
        $failing->shouldReceive('cashierReport')->once()->andThrow(new \RuntimeException('injected failure after audit write'));
        $this->app->instance(ShiftReportCacheInvalidator::class, $failing);

        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Injected failure was swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected failure after audit write', $error->getMessage());
        }

        $this->assertSame($level, DB::transactionLevel(), 'No transaction was leaked open by the failed command.');
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
        $this->assertSame(0, $shift->notWorkedHistory()->count());
        $this->assertSame(0, DB::table('cashier_shift_history')->where('cashier_shift_id', $shift->id)->where('action', 'marked_not_worked')->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->count());
    }
}
