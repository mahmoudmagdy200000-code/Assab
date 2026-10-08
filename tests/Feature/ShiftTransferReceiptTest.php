<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Listeners\BranchManagerShiftListener;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftTransferReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_transfer_request_has_destination_identity_and_no_receipt_until_confirmation(): void
    {
        [$branch, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();

        $transfer = app(ShiftTransferReceiptService::class)->requestManagerCashTransfer(
            $source,
            $cashier,
            $destination,
            '61.50',
            $manager
        );

        $this->assertSame('pending', $transfer->status);
        $this->assertSame($destination->id, $transfer->destination_cashier_shift_id);
        $this->assertNotNull($transfer->report_revision_id);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_recipient_confirmation_records_exact_partial_amount_and_actual_shift(): void
    {
        [$source, $recipient, $destination, $handover, $revision] = $this->handoverFixture('61.50');

        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover(
            $handover->id,
            $recipient,
            '61.00'
        );

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame($revision->id, $receipt->report_revision_id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame(61.0, (float) $destination->fresh()->opening_balance);
        $this->assertSame(2, CashierCustodyTransaction::where('receipt_id', $receipt->id)->count());
        $this->assertSame(['61.00', '61.00'], CashierCustodyTransaction::where('receipt_id', $receipt->id)->orderBy('transaction_type')->pluck('amount')->all());
        $this->assertSame('61.50', $handover->fresh()->handover_amount);
        $this->assertDatabaseHas('cashier_shift_history', [
            'cashier_shift_id' => $destination->id,
            'action' => 'cash_transfer_received',
        ]);
    }

    public function test_manager_approval_does_not_create_a_receipt(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');

        app(HandoverService::class)->approveHandover(
            $source,
            '00000000-0000-0000-0000-000000000001',
            'branch_manager',
            'Reviewed'
        );

        $this->assertSame('approved', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Manager approval must not be treated as recipient confirmation.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_NOT_PENDING', $e->getMessage());
        }
    }

    public function test_receipt_is_immutable_and_duplicate_confirmation_cannot_create_another(): void
    {
        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $service = app(ShiftTransferReceiptService::class);
        $receipt = $service->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        try {
            $service->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('A second confirmation must be rejected.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, CashierShiftHandoverReceipt::count());
        }

        $this->expectException(\LogicException::class);
        $receipt->update(['confirmed_amount' => '1.00']);
    }

    public function test_duplicate_manager_projection_listener_activity_does_not_create_another_receipt(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover(
            $handover->id,
            $recipient,
            '10.00',
            $destination->id
        );

        $listener = app(BranchManagerShiftListener::class);
        $listener->handle($source);
        $listener->handle($source->fresh());

        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame($receipt->id, CashierShiftHandoverReceipt::query()->sole()->id);
    }

    public function test_stale_report_revision_and_wrong_receiving_shift_are_rejected(): void
    {
        [$source, $recipient, $destination, $handover, $revision, $otherShift] = $this->handoverFixture('10.00', true);
        $revisions = app(ShiftReportRevisionService::class);
        DB::transaction(fn () => $revisions->recordCashierRevision($source, 'branch_manager', '00000000-0000-0000-0000-000000000001'));

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('A request from an older report revision must be stale.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('STALE_REPORT_REVISION', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        // Restore the source request to a current revision, then prove the shift owner check.
        $current = $revisions->currentCashierRevision($source);
        $handover->update(['report_revision_id' => $current->id]);
        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $otherShift->id);
            $this->fail('A shift belonging to another cashier must be refused.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('RECEIVING_SHIFT_NOT_AVAILABLE', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_receipt_and_both_custody_rows_roll_back_when_destination_write_fails(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_receiving_custody BEFORE INSERT ON cashier_custody_transactions WHEN NEW.transaction_type = 'Handover Received' BEGIN SELECT RAISE(ABORT, 'injected receiving failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required recipient custody write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_source_custody_failure_rolls_back_receipt_and_all_other_effects(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_sending_custody BEFORE INSERT ON cashier_custody_transactions WHEN NEW.transaction_type = 'Handover Sent' BEGIN SELECT RAISE(ABORT, 'injected sending failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required sender custody write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_required_audit_failure_rolls_back_receipt_state_and_custody(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_transfer_audit BEFORE INSERT ON cashier_shift_history WHEN NEW.action = 'cash_transfer_received' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required audit write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_manager_transfer_confirmation_posts_one_ledger_debit_and_recipient_credit(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.50', $manager);

        $receipt = $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame('confirmed', $transfer->fresh()->status);
        $this->assertSame('61.00', PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $this->assertSame('61.00', CashierCustodyTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $this->assertSame(0, CashierShiftHandover::count());
    }

    public function test_manager_ledger_failure_rolls_back_receipt_transfer_and_recipient_custody(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.50', $manager);
        DB::statement("CREATE TRIGGER fail_manager_ledger BEFORE INSERT ON personal_ledger_transactions BEGIN SELECT RAISE(ABORT, 'injected ledger failure'); END");

        try {
            $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');
            $this->fail('Required manager ledger write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, PersonalLedgerTransaction::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $transfer->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    private function handoverFixture(string $amount, bool $includeOtherShift = false): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $otherCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);
        $destination = CashierShift::factory()->create([
            'cashier_id' => $recipient->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        $otherShift = CashierShift::factory()->create([
            'cashier_id' => $otherCashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $sender->id, 0);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $source->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $source->id,
            'handover_to_id' => $recipient->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => $amount,
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);

        return [$source, $recipient, $destination, $handover, $revision, $otherShift];
    }

    private function managerTransferFixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'cash_collected' => '100.00',
        ]);
        $destination = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        return [$branch, $manager, $cashier, $source, $destination];
    }
}
