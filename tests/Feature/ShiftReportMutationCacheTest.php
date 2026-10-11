<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftFinancialService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\TransferRequestLifecycleService;
use Tests\TestCase;

class ShiftReportMutationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Cache commit tests require disposable SQLite :memory:.');
        }
        // Real outer commits are needed to exercise afterCommit. Rebuild only this in-memory schema.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_financial_cache_reads_corrected_report_after_commit(): void
    {
        [$shift, $cashier, $day] = $this->fixture();
        $financial = app(ShiftFinancialService::class);
        $this->assertEquals(0, $financial->calculateFinancialSummary($day)['card_payments']);
        app(ShiftReportCorrectionService::class)->correctCashierReport($shift, $cashier, ['card_payments' => '1.00'], 1, 'Correct card channel', Str::uuid());
        $this->assertEquals(1, $financial->calculateFinancialSummary($day)['card_payments']);
    }

    public function test_rollback_keeps_cached_report_and_revision(): void
    {
        [$shift, $cashier, $day] = $this->fixture();
        $financial = app(ShiftFinancialService::class);
        $before = $financial->calculateFinancialSummary($day);
        try {
            DB::transaction(function () use ($shift, $cashier) {
                app(ShiftReportCorrectionService::class)->correctCashierReport($shift, $cashier, ['card_payments' => '1.00'], 1, 'Rollback correction', Str::uuid());
                throw new \RuntimeException('rollback audit');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback audit', $exception->getMessage());
        }
        $this->assertSame($before, $financial->calculateFinancialSummary($day));
        $this->assertSame(1, app(ShiftReportRevisionService::class)->currentCashierRevision($shift)->revision_number);
    }

    public function test_replacement_removes_cancelled_request_from_cached_manager_list(): void
    {
        [$shift, $cashier, $day, $request, $destination] = $this->fixture();
        $reader = app(BranchManagerShiftService::class);
        $this->assertCount(1, $reader->getShiftHandovers($day, 'to_manager'));
        app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover', $request->id, $cashier,
            ['recipient_id' => $destination->cashier_id, 'receiving_shift_id' => $destination->id],
            '100.00', 1, 'Replacement cashier available', Str::uuid()
        );
        $this->assertCount(0, $reader->getShiftHandovers($day, 'to_manager'));
        $this->assertEquals(0, app(ShiftFinancialService::class)->calculateFinancialSummary($day)['total_sales']);
    }

    private function fixture(): array
    {
        return DB::transaction(function () {
            $branch = Branch::factory()->create();
            $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
            $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
            $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
            $template = Shift::factory()->create(['branch_id' => $branch->id]);
            $shift = CashierShift::factory()->completed()->create([
                'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(),
                'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'net_sales' => '86.96', 'vat_amount' => '13.04',
            ]);
            $destination = CashierShift::factory()->create(['cashier_id' => $recipient->id, 'shift_id' => $template->id, 'shift_date' => today(), 'status' => 'not_started']);
            $day = BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => today(), 'status' => 'completed']);
            $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $cashier->id, 0);
            app(ShiftCashCountService::class)->record($shift, $revision, 10000, 0, 0, 10000);
            $request = CashierShiftHandover::create(['cashier_shift_id' => $shift->id, 'handover_to_type' => 'branch_manager', 'handover_to_id' => $manager->id, 'handover_amount' => '100.00', 'status' => 'pending', 'report_revision_id' => $revision->id, 'handover_date' => today(), 'handover_time' => now()]);

            return [$shift, $cashier, $day, $request, $destination];
        });
    }
}
