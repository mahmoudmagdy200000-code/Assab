<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Services\ShiftEndService;
use Tests\TestCase;

class ShiftEndAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function openShift(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shift = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'status' => ShiftStatus::IN_PROGRESS,
            'total_sales' => 0,
        ]);

        return [$cashier, $shift];
    }

    public function test_required_cashier_custody_failure_rolls_back_close_report_revision_and_history(): void
    {
        $this->requireSqliteTriggerSupport();
        [$cashier, $shift] = $this->openShift();
        DB::statement("CREATE TRIGGER fail_close_custody BEFORE INSERT ON cashier_custody_transactions WHEN NEW.transaction_type = 'Total Sales' BEGIN SELECT RAISE(ABORT, 'injected custody failure'); END");

        try {
            app(ShiftEndService::class)->endShiftOnly($shift, ['total_sales' => 100, 'cash_collected' => 100], $cashier);
            $this->fail('The required close custody write must fail.');
        } catch (QueryException) {
            $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
            $this->assertSame('0.00', $shift->fresh()->total_sales);
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame(0, ShiftReportAggregate::count());
            $this->assertSame(0, CashierShiftHistory::where('action', 'ended_without_handover')->count());
        }
    }

    public function test_required_close_audit_failure_rolls_back_report_and_custody(): void
    {
        $this->requireSqliteTriggerSupport();
        [$cashier, $shift] = $this->openShift();
        DB::statement("CREATE TRIGGER fail_close_audit BEFORE INSERT ON cashier_shift_history WHEN NEW.action = 'ended_without_handover' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");

        try {
            app(ShiftEndService::class)->endShiftOnly($shift, ['total_sales' => 100, 'cash_collected' => 100], $cashier);
            $this->fail('The required close audit write must fail.');
        } catch (QueryException) {
            $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
            $this->assertSame('0.00', $shift->fresh()->total_sales);
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame(0, ShiftReportAggregate::count());
            $this->assertSame(0, CashierShiftHistory::where('action', 'ended_without_handover')->count());
        }
    }

    private function requireSqliteTriggerSupport(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent database coverage is separate.');
        }
    }
}
