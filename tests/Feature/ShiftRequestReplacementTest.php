<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferAttemptService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Modules\Shift\Services\TransferRequestLifecycleService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class ShiftRequestReplacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_handover_recipient_replacement_cancels_old_and_creates_linked_replacement(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');
        $cashier3 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier3->id,
            'shift_id' => $destShift1->shift_id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $service = app(TransferRequestLifecycleService::class);
        $replacement = $service->replaceRecipient(
            'handover',
            $handover->id,
            $cashier1,
            [
                'recipient_type' => 'cashier',
                'recipient_id' => $cashier3->id,
                'receiving_shift_id' => $destShift2->id,
            ],
            '100.00',
            1,
            'Cashier 2 absent',
            'op-replace-1'
        );

        $this->assertInstanceOf(CashierShiftHandover::class, $replacement);
        $this->assertNotSame($handover->id, $replacement->id);
        $this->assertSame($handover->id, $replacement->supersedes_id);
        $this->assertSame($cashier3->id, $replacement->handover_to_id);
        $this->assertSame('pending', $replacement->status);

        $oldHandover = $handover->fresh();
        $this->assertNotNull($oldHandover->cancelled_at);
        $this->assertSame('cashier', $oldHandover->cancelled_by_type);
        $this->assertSame($cashier1->id, $oldHandover->cancelled_by_id);
        $this->assertSame('Cashier 2 absent', $oldHandover->cancellation_reason);
        $this->assertNotNull($oldHandover->superseded_at);
        $this->assertSame($replacement->id, $oldHandover->replacement_request_id);

        // Old recipient cannot confirm old request
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_CANCELLED');
        app(ShiftTransferReceiptService::class)->confirmHandover($oldHandover->id, $cashier2, '100.00', $destShift1->id);
    }

    public function test_manager_transfer_recipient_replacement_cancels_old_and_creates_linked_replacement(): void
    {
        [$manager, $cashier1, $sourceDay, $destShift1, $transfer] = $this->managerTransferFixture('60.00');
        $cashier2 = Cashier::factory()->create(['branch_id' => $sourceDay->branch_id, 'created_by' => $manager->id]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $destShift1->shift_id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $service = app(TransferRequestLifecycleService::class);
        $replacement = $service->replaceRecipient(
            'manager_transfer',
            $transfer->id,
            $manager,
            [
                'recipient_id' => $cashier2->id,
                'destination_cashier_shift_id' => $destShift2->id,
            ],
            '80.00',
            1,
            'Assigning to cashier 2 instead',
            'op-replace-mgr-1'
        );

        $this->assertInstanceOf(BranchManagerCashTransfer::class, $replacement);
        $this->assertNotSame($transfer->id, $replacement->id);
        $this->assertSame($transfer->id, $replacement->supersedes_id);
        $this->assertSame($cashier2->id, $replacement->destination_cashier_id);
        $this->assertSame('80.00', (string) $replacement->requested_amount);

        $oldTransfer = $transfer->fresh();
        $this->assertNotNull($oldTransfer->cancelled_at);
        $this->assertSame($replacement->id, $oldTransfer->replacement_request_id);

        // Old recipient cannot confirm old transfer
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_CANCELLED');
        app(ShiftTransferReceiptService::class)->confirmManagerCashTransfer($oldTransfer->id, $cashier1, '60.00');
    }

    public function test_replacement_rejects_empty_reason_with_422(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('CANCELLATION_REASON_REQUIRED');

        app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover',
            $handover->id,
            $cashier1,
            ['recipient_id' => $cashier2->id],
            '100.00',
            1,
            '',
            'op-empty-reason'
        );
    }

    public function test_replacement_rejects_stale_revision_with_409(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('STALE_REPORT_REVISION');

        app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover',
            $handover->id,
            $cashier1,
            ['recipient_id' => $cashier2->id],
            '100.00',
            99, // Stale revision
            'Some reason',
            'op-stale-rev'
        );
    }

    public function test_replacement_rejects_confirmed_receipt_with_409(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');

        // Confirm handover to create receipt
        app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $cashier2, '100.00', $destShift1->id);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('CONFIRMED_RECEIPT_IMMUTABLE');

        app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover',
            $handover->id,
            $cashier1,
            ['recipient_id' => $cashier2->id],
            '100.00',
            1,
            'Want to replace confirmed',
            'op-confirmed-replace'
        );
    }

    public function test_replacement_rejects_unreturned_physical_cash_with_409_physical_return_required(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');

        // Present transfer
        $attempt = app(ShiftTransferAttemptService::class)->present(
            'handover',
            $handover->id,
            $cashier1,
            10000,
            'attempt-key-1'
        );

        // Reject attempt with retained cash
        app(ShiftTransferAttemptService::class)->rejectAttempt(
            $attempt->id,
            $cashier2,
            5000, // 50.00 SAR physical cash retained!
            'Short physical cash',
            'input_error'
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('PHYSICAL_RETURN_REQUIRED');

        $currentRev = app(ShiftReportRevisionService::class)->currentCashierRevision($sourceShift)->revision_number;

        app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover',
            $handover->id,
            $cashier1,
            ['recipient_id' => $cashier2->id],
            '100.00',
            $currentRev,
            'Replace while cash retained',
            'op-unreturned-replace'
        );
    }

    public function test_replacement_rolls_back_when_history_insert_fails(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift1, $handover] = $this->handoverFixture('100.00');
        $cashier3 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier3->id,
            'shift_id' => $destShift1->shift_id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $service = app(TransferRequestLifecycleService::class);

        // Invalidate history table to provoke failure during transaction
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'cashier_shift_history') && str_contains(strtolower($query->sql), 'insert')) {
                throw new \RuntimeException('Simulated history failure');
            }
        });

        try {
            $service->replaceRecipient(
                'handover',
                $handover->id,
                $cashier1,
                [
                    'recipient_type' => 'cashier',
                    'recipient_id' => $cashier3->id,
                    'receiving_shift_id' => $destShift2->id,
                ],
                '100.00',
                1,
                'Should fail and rollback',
                'op-rollback'
            );
            $this->fail('Should have thrown RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated history failure', $e->getMessage());
        }

        // Old handover must NOT be cancelled
        $fresh = $handover->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->superseded_at);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, CashierShiftHandover::count());
    }

    private function handoverFixture(string $amount): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier1->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $cashier1->id, 0);
        $minor = (int) round((float) $amount * 100);
        app(ShiftCashCountService::class)->record($sourceShift, $revision, $minor, 0, 0, $minor);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $cashier2->id,
            'handover_amount' => $amount,
            'status' => 'pending',
            'report_revision_id' => $revision->id,
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_id' => $handover->id,
            'status' => 'pending',
            'rejection_count' => 0,
        ]);

        return [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover];
    }

    private function managerTransferFixture(string $amount): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceDay = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'cash_collected' => '200.00',
        ]);
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '200.00',
            'is_cash_in' => true,
            'related_shift_id' => $sourceDay->id,
            'transaction_date' => now(),
        ]);

        $destShift = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($sourceDay, $cashier, $destShift, $amount, $manager);

        return [$manager, $cashier, $sourceDay, $destShift, $transfer];
    }
}
