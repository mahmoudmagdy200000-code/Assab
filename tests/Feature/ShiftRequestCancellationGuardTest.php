<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferAttemptService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftRequestCancellationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_handover_rejects_cancelled_request_with_409_handover_cancelled(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover] = $this->handoverFixture('100.00');

        $handover->update([
            'cancelled_at' => now(),
            'cancelled_by_type' => 'cashier',
            'cancelled_by_id' => $cashier1->id,
            'cancellation_reason' => 'Wrong recipient',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_CANCELLED');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $cashier2, '100.00', $destShift->id);
        } finally {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame(0, PersonalLedgerTransaction::count());
        }
    }

    public function test_confirm_handover_rejects_superseded_request_with_409_handover_superseded(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover] = $this->handoverFixture('100.00');

        $handover->update([
            'superseded_at' => now(),
            'supersedes_id' => null,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_SUPERSEDED');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $cashier2, '100.00', $destShift->id);
        } finally {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
        }
    }

    public function test_confirm_manager_transfer_rejects_cancelled_request_with_409_handover_cancelled(): void
    {
        [$manager, $cashier, $sourceDay, $destShift, $transfer] = $this->managerTransferFixture('50.00');

        $transfer->update([
            'cancelled_at' => now(),
            'cancelled_by_type' => 'branch_manager',
            'cancelled_by_id' => $manager->id,
            'cancellation_reason' => 'Cancelled by manager',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_CANCELLED');

        try {
            app(ShiftTransferReceiptService::class)->confirmManagerCashTransfer($transfer->id, $cashier, '50.00');
        } finally {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
        }
    }

    public function test_confirm_manager_transfer_rejects_superseded_request_with_409_handover_superseded(): void
    {
        [$manager, $cashier, $sourceDay, $destShift, $transfer] = $this->managerTransferFixture('50.00');

        $transfer->update([
            'superseded_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_SUPERSEDED');

        try {
            app(ShiftTransferReceiptService::class)->confirmManagerCashTransfer($transfer->id, $cashier, '50.00');
        } finally {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
        }
    }

    public function test_attempt_presentation_rejects_cancelled_request_with_409_handover_cancelled(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover] = $this->handoverFixture('100.00');

        $handover->update([
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_CANCELLED');

        app(ShiftTransferAttemptService::class)->present(
            'handover',
            $handover->id,
            $cashier1,
            10000,
            (string) \Illuminate\Support\Str::uuid()
        );
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

        \Modules\Shift\Models\ShiftHandoverStatus::create([
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
