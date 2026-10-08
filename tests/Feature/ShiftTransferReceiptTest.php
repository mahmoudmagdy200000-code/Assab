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

    public function test_recipient_confirmation_rejects_any_amount_mismatch_without_effects(): void
    {
        [$source, $recipient, $destination, $handover, $revision] = $this->handoverFixture('61.50');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00');
            $this->fail('A partial physical amount requires rejection and request correction first.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }

        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame('61.50', $handover->fresh()->handover_amount);
        $this->assertSame($revision->id, $handover->fresh()->report_revision_id);
        $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
    }

    public function test_zero_confirmation_for_nonzero_request_is_a_mismatch(): void
    {
        [, $recipient, , $handover] = $this->handoverFixture('10.00');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '0.00');
            $this->fail('Zero cannot confirm a nonzero request.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }

        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('pending', $handover->fresh()->status);
    }

    public function test_rejected_cashier_handover_can_be_corrected_and_then_confirmed_exactly(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('61.50');
        $service = app(HandoverService::class);
        $service->rejectHandoverForAmountCorrection($source, $recipient->id, get_class($recipient), 'Physical amount was 61.00', '61.00', 'actual_shortage');
        $this->actingAs($source->cashier, 'sanctum');
        $service->recordHandoverEdit($source, [
            'handover_amount' => '61.00',
            'handover_notes' => 'Corrected after recipient count',
            'correction_reason' => 'actual_shortage',
        ]);
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00', $destination->id);

        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('actual_shortage', $history->new_value['correction_reason']);
        $rejectionHistory = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_amount_correction_rejected')
            ->sole();
        $this->assertSame('61.00', $rejectionHistory->new_value['attempted_confirmed_amount']);
        $this->assertSame($recipient->id, $rejectionHistory->new_value['actor_id']);
        $this->assertNotNull($history->created_at);
        $this->assertSame($source->cashier_id, $history->new_value['actor_id']);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame(2, CashierCustodyTransaction::where('receipt_id', $receipt->id)->count());
    }

    public function test_input_error_correction_records_old_and_new_amount_before_exact_confirmation(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('61.50');
        $service = app(HandoverService::class);
        $service->rejectHandoverForAmountCorrection($source, $recipient->id, get_class($recipient), 'Entered amount was wrong', '61.00', 'input_error');
        $this->actingAs($source->cashier, 'sanctum');
        $service->recordHandoverEdit($source, [
            'handover_amount' => '61.00',
            'correction_reason' => 'input_error',
        ]);

        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('input_error', $history->new_value['correction_reason']);
        $this->assertSame($source->cashier_id, $history->new_value['actor_id']);
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00', $destination->id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
    }

    public function test_manager_confirmation_creates_manager_bound_receipt_and_effects(): void
    {
        [$branch, $manager, $sender, $source, $destination, $handover, $revision] = $this->managerHandoverFixture('61.50');
        app(HandoverService::class)->approveHandover($source, $manager->id, get_class($manager), null, '61.50');

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertNull($receipt->receiving_cashier_shift_id);
        $this->assertSame($manager->id, $receipt->receiving_branch_manager_id);
        $this->assertNotNull($receipt->receiving_branch_manager_shift_id);
        $this->assertSame($manager->id, $receipt->confirmed_by_branch_manager_id);
        $this->assertSame($revision->id, $receipt->report_revision_id);
        $this->assertSame('61.50', $receipt->confirmed_amount);
        $this->assertSame('approved', $handover->fresh()->status);
        $this->assertDatabaseHas('cashier_custody_transactions', [
            'receipt_id' => $receipt->id,
            'cashier_id' => $sender->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '61.50',
            'is_cash_in' => 0,
        ]);
        $ledger = PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole();
        $this->assertSame('Total Sales', $ledger->transaction_type);
        $this->assertSame('61.50', $ledger->amount);
        $this->assertSame($manager->id, $ledger->branch_manager_id);
        $this->assertSame($receipt->confirmed_at->toDateString(), $ledger->transaction_date->toDateString());
        $this->assertSame(0, CashierCustodyTransaction::where('receipt_id', $receipt->id)->where('transaction_type', 'Handover Received')->count());
    }

    public function test_manager_review_does_not_approve_cashier_request_and_named_cashier_still_confirms(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');

        app(HandoverService::class)->approveHandover(
            $source,
            '00000000-0000-0000-0000-000000000001',
            'branch_manager',
            'Reviewed'
        );

        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('10.00', app(ShiftTransferReceiptService::class)
            ->confirmHandover($handover->id, $recipient, '10.00', $destination->id)->confirmed_amount);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
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

    public function test_manager_transfer_requires_correction_then_posts_exactly_the_corrected_amount(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.50', $manager);

        try {
            $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');
            $this->fail('Manager transfer confirmation must match the request exactly.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        $service->rejectManagerCashTransfer($transfer->id, $cashier, '61.00', 'Actual cash was short by 0.50', 'actual_shortage');
        $service->correctManagerCashTransfer($transfer->id, $manager, '61.00', 'actual_shortage');
        $receipt = $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame('confirmed', $transfer->fresh()->status);
        $this->assertSame('61.00', PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $this->assertSame('61.00', CashierCustodyTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $destination->id)
            ->where('action', 'manager_transfer_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('actual_shortage', $history->new_value['correction_reason']);
        $rejection = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $destination->id)
            ->where('action', 'manager_transfer_amount_correction_rejected')
            ->sole();
        $this->assertSame('61.00', $rejection->new_value['attempted_confirmed_amount']);
        $this->assertSame($cashier->id, $rejection->new_value['actor_id']);
        $this->assertStringContainsString('no liability allocation inferred', $history->new_value['evidence_note']);
        $this->assertSame($manager->id, $history->new_value['actor_id']);
        $this->assertNotNull($history->created_at);
        $this->assertSame(0, CashierShiftHandover::count());
    }

    public function test_manager_ledger_failure_rolls_back_receipt_transfer_and_recipient_custody(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.00', $manager);
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

    private function managerHandoverFixture(string $amount): array
    {
        [$branch, $manager, $cashier, $managerWorkday, $destination] = $this->managerTransferFixture();
        $sourceCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $sourceShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $sourceCashier->id,
            'shift_id' => $destination->shift_id,
            'shift_date' => today(),
        ]);
        $sender = $sourceShift->cashier;
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $sender->id, 0);
        $managerWorkday->update(['status' => 'active', 'cash_collected' => $amount]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_id' => $manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => $amount,
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);

        return [$branch, $manager, $sender, $sourceShift, $destination, $handover, $revision];
    }
}
