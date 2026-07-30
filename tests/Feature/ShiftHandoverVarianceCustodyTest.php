<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Services\HandoverService;
use Tests\TestCase;

class ShiftHandoverVarianceCustodyTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_cashier_reject_reverts_shift_and_strips_custody(): void
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
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $c2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => 100,
            'variance_amount' => 0,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $c1->id,
            'transaction_type' => 'Handover Sent',
            'amount' => 500,
            'is_cash_in' => false,
            'counterpart_name' => null,
            'related_shift_id' => $cashierShift->id,
            'related_handover_id' => null,
            'transaction_date' => now(),
        ]);

        $service = app(HandoverService::class);
        $service->rejectHandoverByCashier($cashierShift->fresh(['handoverStatus']), $c2->id, 'Test reject', []);

        $fresh = $cashierShift->fresh();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $fresh->status);
        $this->assertEquals(0, (float) $fresh->total_sales);
        $this->assertNull($fresh->next_cashier_id);
        $this->assertDatabaseMissing('shift_handover_status', ['cashier_shift_id' => $cashierShift->id]);
        $this->assertDatabaseMissing('cashier_shift_handovers', ['cashier_shift_id' => $cashierShift->id]);
        $this->assertEquals(0, CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)->count());
    }

    public function test_accept_handover_by_cashier_marks_shift_completed(): void
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
            'closing_balance' => 200,
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $cashierShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);

        CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'handover_to_id' => $c2->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => 200,
            'variance_amount' => 0,
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
        ]);

        $service = app(HandoverService::class);
        $service->acceptHandoverByCashier($cashierShift->fresh(['handoverStatus']), $c2->id, null);

        $this->assertSame(ShiftStatus::COMPLETED, $cashierShift->fresh()->status);
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

        $txn = CashierCustodyTransaction::where('related_shift_id', $cashierShift->id)
            ->where('transaction_type', 'Variance')
            ->first();
        $this->assertNotNull($txn);
        $this->assertTrue($txn->is_cash_in);
    }
}
