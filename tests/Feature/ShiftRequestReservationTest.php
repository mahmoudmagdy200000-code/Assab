<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Modules\Shift\Services\TransferRequestLifecycleService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftRequestReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_reservation_releases_old_and_acquires_new_amount(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceDay = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'cash_collected' => '100.00',
        ]);
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '100.00',
            'is_cash_in' => true,
            'related_shift_id' => $sourceDay->id,
            'transaction_date' => now(),
        ]);

        $destShift1 = CashierShift::factory()->create([
            'cashier_id' => $cashier1->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        $destShift2 = CashierShift::factory()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $receiptService = app(ShiftTransferReceiptService::class);
        $transfer = $receiptService->requestManagerCashTransfer($sourceDay, $cashier1, $destShift1, '60.00', $manager);

        // Available is now 100 - 60 = 40.00 SAR (4000 minor)
        $this->assertSame(4000, $receiptService->availableManagerCashMinor($manager->id));

        // Replace recipient with 80.00 SAR to cashier 2
        $lifecycleService = app(TransferRequestLifecycleService::class);
        $replacement = $lifecycleService->replaceRecipient(
            'manager_transfer',
            $transfer->id,
            $manager,
            [
                'recipient_id' => $cashier2->id,
                'destination_cashier_shift_id' => $destShift2->id,
            ],
            '80.00',
            1,
            'Changing recipient to cashier 2 with 80 SAR',
            'op-res-1'
        );

        // Available is now 100 - 80 = 20.00 SAR (2000 minor)
        $this->assertSame(2000, $receiptService->availableManagerCashMinor($manager->id));

        // Attempting to replace with 110.00 SAR exceeds total 100.00 SAR -> 409
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('INSUFFICIENT_RECORDED_SALES_CASH');

        $lifecycleService->replaceRecipient(
            'manager_transfer',
            $replacement->id,
            $manager,
            [
                'recipient_id' => $cashier1->id,
                'destination_cashier_shift_id' => $destShift1->id,
            ],
            '110.00',
            1,
            'Over reservation attempt',
            'op-res-2'
        );
    }
}
