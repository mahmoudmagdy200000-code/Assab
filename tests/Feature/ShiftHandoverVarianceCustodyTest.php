<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Listeners\CreateCustodyLedgerEntriesForVariance;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ResponsibilityType;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Enums\VarianceType;
use Modules\Shift\Events\VarianceRecorded;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftReportRevisionSnapshotService;
use Tests\TestCase;

class ShiftHandoverVarianceCustodyTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_cashier_reject_reopens_shift_and_preserves_report_and_custody(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $c1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $c2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $c1->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'next_cashier_id' => $c2->id,
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'closing_balance' => '100.00',
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $c1->id, 0);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $c2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => 100,
            'variance_amount' => 0,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);
        $snapshot = app(ShiftReportRevisionSnapshotService::class)->createSnapshotIfMissing($revision, $cashierShift);
        $oldSnapshot = $snapshot->snapshot_data;

        $custody = CashierCustodyTransaction::create([
            'cashier_id' => $c1->id,
            'transaction_type' => 'Handover Sent',
            'amount' => 500,
            'is_cash_in' => false,
            'counterpart_name' => null,
            'related_shift_id' => $cashierShift->id,
            'related_handover_id' => null,
            'transaction_date' => now(),
        ]);
        $oldCustody = $custody->fresh()->getAttributes();

        $service = app(HandoverService::class);
        $service->rejectHandoverByCashier($cashierShift->fresh(['handoverStatus']), $c2->id, 'Test reject', []);

        $fresh = $cashierShift->fresh();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $fresh->status);
        $this->assertSame('500.00', (string) $fresh->total_sales);
        $this->assertSame('500.00', (string) $fresh->cash_collected);
        $this->assertSame('100.00', (string) $fresh->closing_balance);
        $this->assertSame($c2->id, $fresh->next_cashier_id);
        $this->assertSame('rejected', $fresh->handoverStatus->manager_approval_status);
        $this->assertSame('rejected', $handover->fresh()->status);
        $this->assertSame($oldSnapshot, $snapshot->fresh()->snapshot_data);
        $this->assertSame('500.00', $snapshot->fresh()->snapshot_data['total_sales']);
        $this->assertSame($oldCustody, $custody->fresh()->getAttributes());
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());
        $this->assertNotSame($revision->id, app(ShiftReportRevisionService::class)->currentCashierRevision($fresh)->id);
    }

    public function test_required_rejection_history_insert_failure_rolls_back_handover_rejection(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'next_cashier_id' => $recipient->id,
            'total_sales' => 100,
        ]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $recipient->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => 100,
            'variance_amount' => 0,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);
        CashierCustodyTransaction::create([
            'cashier_id' => $sender->id,
            'transaction_type' => 'Total Sales',
            'amount' => 100,
            'is_cash_in' => true,
            'related_shift_id' => $cashierShift->id,
            'transaction_date' => now(),
        ]);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $sender->id, 0);
        $handover->update(['report_revision_id' => $revision->id]);
        DB::statement("CREATE TRIGGER fail_rejection_history BEFORE INSERT ON cashier_shift_history WHEN NEW.action = 'handover_rejected_shift_reverted' BEGIN SELECT RAISE(ABORT, 'injected required rejection history failure'); END");

        try {
            app(HandoverService::class)->rejectHandoverByCashier($cashierShift, $recipient->id, 'Reject handover', []);
            $this->fail('Required rejection history failure must abort the rejection transaction.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('injected required rejection history failure', $e->getMessage());
            $this->assertSame(ShiftStatus::COMPLETED, $cashierShift->fresh()->status);
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame('pending', $cashierShift->fresh()->handoverStatus->manager_approval_status);
            $this->assertSame($revision->id, app(ShiftReportRevisionService::class)->currentCashierRevision($cashierShift)->id);
            $this->assertSame(0, ShiftReportRevisionSnapshot::where('report_revision_id', $revision->id)->count());
            $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());
            $this->assertSame(0, DB::table('cashier_shift_history')->where('action', 'handover_rejected_shift_reverted')->count());
        }
    }

    public function test_legacy_backfill_refuses_to_infer_receipt_or_liability_approval(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $shift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'variance' => -50,
        ]);
        ShiftVarianceDetail::create([
            'cashier_shift_id' => $shift->id,
            'variance_amount' => 50,
            'variance_type' => VarianceType::SHORT,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $cashier->id,
            'assigned_amount' => 50,
            'reason' => 'pending employee response',
            'responsibility_status' => 'pending',
        ]);
        CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_id' => $manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => 100,
            'variance_amount' => -50,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'approved',
            'approved_by_id' => $manager->id,
            'approved_by_type' => BranchManager::class,
            'approved_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('custody:backfill-cashier-ledger'));
        $this->assertSame('pending', $shift->varianceDetails()->sole()->responsibility_status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
        $this->assertSame(0, DB::table('personal_ledger_transactions')->count());
    }

    public function test_accept_handover_by_cashier_marks_shift_completed(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $c1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $c2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);

        $receivingShift = CashierShift::factory()->create([
            'cashier_id' => $c2->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $c1->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'next_cashier_id' => $c2->id,
            'closing_balance' => 200,
            'total_sales' => 200,
            'cash_collected' => 200,
            'card_payments' => 0,
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision(
            $cashierShift,
            'cashier',
            $c1->id,
            0
        );
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($cashierShift, $revision, 20000, 0, 0, 20000));

        CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $c2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => 200,
            'variance_amount' => 0,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);

        $service = app(HandoverService::class);
        $service->acceptHandoverByCashier($cashierShift->fresh(['handoverStatus']), $c2->id, '200.00', $receivingShift->id, null);

        $this->assertSame(ShiftStatus::COMPLETED, $cashierShift->fresh()->status);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertDatabaseHas('cashier_shift_handover_receipts', [
            'receiving_cashier_shift_id' => $receivingShift->id,
            'confirmed_amount' => '200.00',
            'report_revision_id' => $revision->id,
        ]);
        $this->assertSame(200.0, (float) $receivingShift->fresh()->opening_balance);
        $this->assertDatabaseHas('cashier_shift_handovers', [
            'cashier_shift_id' => $cashierShift->id,
            'status' => 'approved',
        ]);
    }

    public function test_variance_custody_listener_only_writes_when_responsibility_approved(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'variance' => -50,
        ]);

        $detail = ShiftVarianceDetail::create([
            'cashier_shift_id' => $cashierShift->id,
            'variance_amount' => 50,
            'variance_type' => VarianceType::SHORT,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $cashier->id,
            'assigned_amount' => 50,
            'reason' => 'test',
            'responsibility_status' => 'pending',
        ]);

        $listener = app(CreateCustodyLedgerEntriesForVariance::class);
        $listener->handle(new VarianceRecorded($cashierShift->fresh(['varianceDetails', 'handover'])));

        $this->assertEquals(0, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)
            ->where('transaction_type', 'Variance')
            ->count());

        $detail->update(['responsibility_status' => 'approved']);
        $listener->handle(new VarianceRecorded($cashierShift->fresh(['varianceDetails', 'handover'])));

        $txn = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)
            ->where('transaction_type', 'Variance')
            ->first();
        $this->assertNotNull($txn);
        $this->assertFalse($txn->is_cash_in);
        $this->assertEquals(50.0, (float) $txn->amount);
    }

    public function test_variance_over_uses_cash_in_for_cashier(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'variance' => 40,
        ]);

        ShiftVarianceDetail::create([
            'cashier_shift_id' => $cashierShift->id,
            'variance_amount' => 40,
            'variance_type' => VarianceType::OVER,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $cashier->id,
            'assigned_amount' => 40,
            'reason' => 'test',
            'responsibility_status' => 'approved',
        ]);

        $listener = app(CreateCustodyLedgerEntriesForVariance::class);
        $listener->handle(new VarianceRecorded($cashierShift->fresh(['varianceDetails', 'handover'])));
        $listener->handle(new VarianceRecorded($cashierShift->fresh(['varianceDetails', 'handover'])));

        $txn = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)
            ->where('transaction_type', 'Variance')
            ->first();
        $this->assertNotNull($txn);
        $this->assertTrue($txn->is_cash_in);
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)
            ->where('transaction_type', 'Variance')->count());
    }

    public function test_manager_handover_receipt_writes_sales_credit_without_finalizing_legacy_variance(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shiftTemplate = Shift::factory()->create(['branch_id' => $branch->id]);

        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $shiftTemplate->id,
            'shift_date' => today(),
            'variance' => 1500,
            'total_sales' => 3500,
            'cash_collected' => 3500,
            'card_payments' => 0,
            'closing_balance' => 3500,
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => 3500,
            'variance_amount' => 1500,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);
        $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)
            ->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
        $handover->update(['report_revision_id' => $revision->id]);
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($cashierShift, $revision, 350000, 0, 0, 350000));
        $managerWorkday = BranchManagerShift::query()->where('branch_manager_id', $manager->id)->first();
        if (! $managerWorkday) {
            $managerWorkday = new BranchManagerShift([
                'branch_manager_id' => $manager->id,
                'branch_id' => $branch->id,
                'shift_date' => today()->toDateString(),
            ]);
        }
        $managerWorkday->fill([
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'active',
            'cash_collected' => '3500.00',
        ])->save();

        // Cashier declared the variance on himself at end-shift → detail stays
        // pending; nobody calls a separate responsibility-approval endpoint.
        ShiftVarianceDetail::create([
            'cashier_shift_id' => $cashierShift->id,
            'variance_amount' => 1500,
            'variance_type' => VarianceType::OVER,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $cashier->id,
            'assigned_amount' => 1500,
            'reason' => 'test',
            'responsibility_status' => 'pending',
        ]);

        app(HandoverService::class)->approveHandover(
            $cashierShift->fresh(['handoverStatus', 'cashier']),
            $manager->id,
            get_class($manager),
            null,
            '3500.00'
        );

        // Receipt confirmation does not finalize liability evidence.
        $this->assertDatabaseHas('shift_variance_details', [
            'cashier_shift_id' => $cashierShift->id,
            'responsible_cashier_id' => $cashier->id,
            'responsibility_status' => 'pending',
        ]);

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertDatabaseHas('cashier_custody_transactions', [
            'cashier_id' => $cashier->id,
            'related_shift_id' => $cashierShift->id,
            'transaction_type' => 'Handover Sent',
            'receipt_id' => $receipt->id,
            'amount' => '3500.00',
            'is_cash_in' => 0,
        ]);
        $this->assertDatabaseHas('personal_ledger_transactions', [
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'related_handover_id' => $handover->id,
            'receipt_id' => $receipt->id,
            'amount' => '3500.00',
        ]);
        $this->assertSame(0, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->where('transaction_type', 'Variance')->count());
    }

    public function test_required_manager_receipt_ledger_failure_rolls_back_receipt_and_handover_confirmation(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent database coverage is separate.');
        }

        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'variance' => 150,
            'total_sales' => 100,
            'cash_collected' => 100,
            'card_payments' => 0,
            'closing_balance' => 100,
        ]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => 100,
            'variance_amount' => 150,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);
        $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)
            ->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
        $handover->update(['report_revision_id' => $revision->id]);
        DB::transaction(fn () => app(ShiftCashCountService::class)->record($cashierShift, $revision, 10000, 0, 0, 10000));
        $managerWorkday = BranchManagerShift::query()->where('branch_manager_id', $manager->id)->first();
        if (! $managerWorkday) {
            $managerWorkday = new BranchManagerShift([
                'branch_manager_id' => $manager->id,
                'branch_id' => $branch->id,
                'shift_date' => today()->toDateString(),
            ]);
        }
        $managerWorkday->fill([
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'active',
            'cash_collected' => '100.00',
        ])->save();
        $detail = ShiftVarianceDetail::create([
            'cashier_shift_id' => $cashierShift->id,
            'variance_amount' => 150,
            'variance_type' => VarianceType::SHORT,
            'responsibility_type' => ResponsibilityType::I_WAS_RESPONSIBLE,
            'responsible_cashier_id' => $cashier->id,
            'assigned_amount' => 150,
            'reason' => 'test',
            'responsibility_status' => 'pending',
        ]);
        DB::statement("CREATE TRIGGER fail_manager_total_sales BEFORE INSERT ON personal_ledger_transactions WHEN NEW.transaction_type = 'Total Sales' BEGIN SELECT RAISE(ABORT, 'injected manager ledger failure'); END");

        try {
            app(HandoverService::class)->approveHandover($cashierShift, $manager->id, get_class($manager), null, '100.00');
            $this->fail('Required manager receipt ledger failure must abort confirmation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('injected manager ledger failure', $e->getMessage());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame('pending', $detail->fresh()->responsibility_status);
            $this->assertSame('pending', $cashierShift->fresh()->handoverStatus->manager_approval_status);
            $this->assertSame(0, CashierCustodyTransaction::where('transaction_type', 'Variance')->count());
            $this->assertSame(0, \Modules\Custody\Models\PersonalLedgerTransaction::where('transaction_type', 'Variance from Cashier')->count());
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
        }
    }
}
