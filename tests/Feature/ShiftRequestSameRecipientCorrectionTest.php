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
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftRequestSameRecipientCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_to_cashier_same_recipient_correction_preserves_request_id_and_evidence(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover] = $this->handoverFixture('100.00');

        // Reject handover
        $handoverService = app(HandoverService::class);
        $handoverService->rejectHandoverByCashier($sourceShift, $cashier2->id, 'Rejected amount');

        $originalHandoverId = $handover->id;
        $originalRejectionReason = $handover->fresh()->rejection_reason;

        // Perform edit with same recipient
        $this->actingAs($cashier1);
        $updatedShift = $handoverService->recordHandoverEdit($sourceShift, [
            'handover_amount' => '120.00',
            'handover_notes' => 'Corrected amount',
            'correction_reason' => 'input_error',
        ]);

        $freshHandover = CashierShiftHandover::findOrFail($originalHandoverId);
        $this->assertSame($originalHandoverId, $freshHandover->id);
        $this->assertSame('120.00', (string) $freshHandover->handover_amount);
        $this->assertSame('pending', $freshHandover->status);
        $this->assertSame('cashier', $freshHandover->handover_to_type);
        $this->assertSame($cashier2->id, $freshHandover->handover_to_id);
        $this->assertSame($originalRejectionReason, $freshHandover->rejection_reason);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
    }

    public function test_record_handover_edit_rejects_recipient_change_with_409(): void
    {
        [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover] = $this->handoverFixture('100.00');
        $cashier3 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        $handoverService = app(HandoverService::class);
        $handoverService->rejectHandoverByCashier($sourceShift, $cashier2->id, 'Wrong amount');

        $this->actingAs($cashier1);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT');

        $handoverService->recordHandoverEdit($sourceShift, [
            'handover_amount' => '100.00',
            'handover_to_id' => $cashier3->id,
            'correction_reason' => 'input_error',
        ]);
    }

    public function test_manager_to_cashier_same_recipient_correction_preserves_request_id(): void
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
        $transfer = $service->requestManagerCashTransfer($sourceDay, $cashier, $destShift, '50.00', $manager);
        $originalTransferId = $transfer->id;

        // Reject manager transfer
        $service->rejectManagerCashTransfer($transfer->id, $cashier, '40.00', 'Count mismatch', 'input_error');

        $this->assertSame('rejected', $transfer->fresh()->status);

        // Correct manager transfer
        $corrected = $service->correctManagerCashTransfer($transfer->id, $manager, '40.00', 'input_error');

        $this->assertSame($originalTransferId, $corrected->id);
        $this->assertSame('pending', $corrected->fresh()->status);
        $this->assertSame('40.00', (string) $corrected->fresh()->requested_amount);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
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
}
