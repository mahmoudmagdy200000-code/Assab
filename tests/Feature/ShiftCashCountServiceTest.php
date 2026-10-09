<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Modules\Shift\Services\ShiftCashCountService;
use Tests\TestCase;

/** S1-10: pending-incoming (D11) resolution and evidence immutability, independent of the report routes. */
class ShiftCashCountServiceTest extends TestCase
{
    use RefreshDatabase;

    private function shift(): CashierShift
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        return CashierShift::factory()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today(), 'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHour(),
        ]);
    }

    private function handover(CashierShift $shift): CashierShiftHandover
    {
        return CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id, 'handover_to_id' => $shift->cashier_id, 'handover_to_type' => 'cashier',
            'handover_amount' => '500.00', 'status' => 'pending', 'handover_date' => today(), 'handover_time' => now(),
        ]);
    }

    public function test_only_the_latest_rejection_evidence_per_request_is_pending_incoming(): void
    {
        $shift = $this->shift();
        $service = app(ShiftCashCountService::class);
        $handoverId = $this->handover($shift)->id;

        $this->assertSame(0, $service->confirmedOpeningHalalas($shift->id));
        $this->assertSame(0, $service->pendingIncomingHalalas($shift));

        foreach ([['480.00', now()->subMinutes(10)], ['470.00', now()->subMinutes(5)]] as [$physical, $at]) {
            ShiftTransferRejectionEvidence::create([
                'cashier_shift_handover_id' => $handoverId, 'recipient_type' => 'cashier', 'recipient_id' => $shift->cashier_id,
                'receiving_cashier_shift_id' => $shift->id, 'requested_halalas' => 50000,
                'physical_halalas' => (int) round((float) $physical * 100), 'correction_reason' => 'actual_shortage', 'rejected_at' => $at,
            ]);
        }

        $this->assertSame(47000, $service->pendingIncomingHalalas($shift), 'the latest counted amount replaces the earlier one');
    }

    public function test_rejection_evidence_is_append_only(): void
    {
        $shift = $this->shift();
        $evidence = ShiftTransferRejectionEvidence::create([
            'cashier_shift_handover_id' => $this->handover($shift)->id, 'recipient_type' => 'cashier',
            'recipient_id' => $shift->cashier_id, 'receiving_cashier_shift_id' => $shift->id,
            'requested_halalas' => 50000, 'physical_halalas' => 48000, 'correction_reason' => 'input_error', 'rejected_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $evidence->update(['physical_halalas' => 1]);
    }
}
