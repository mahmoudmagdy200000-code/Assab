<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftReportBalanceCorrectionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftReportBalanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Cashier $cashier;

    private Shift $shiftTemplate;

    private CashierShift $cashierShift;

    private ShiftReportBalanceCorrectionService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);

        $this->cashierShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'cash_collected' => '60.00',
        ]);

        $this->balanceService = app(ShiftReportBalanceCorrectionService::class);
    }

    public function test_positive_cash_collected_delta_creates_cash_in_entry(): void
    {
        // Initial entry of 60.00 SAR
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '60.00',
            'is_cash_in' => true,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        // Target updated to 80.00 SAR -> delta +20.00 SAR
        $this->balanceService->reconcileCashCollected(
            $this->cashierShift,
            oldCashCollectedHalalas: 6000,
            newCashCollectedHalalas: 8000,
            operationId: (string) Str::uuid()
        );

        $transactions = CashierCustodyTransaction::where('related_shift_id', $this->cashierShift->id)
            ->where('transaction_type', 'Total Sales')
            ->get();

        $this->assertCount(2, $transactions);
        $delta = $transactions->last();
        $this->assertSame('20.00', (string) $delta->amount);
        $this->assertTrue((bool) $delta->is_cash_in);
    }

    public function test_negative_cash_collected_delta_creates_cash_out_entry(): void
    {
        // Initial entry of 60.00 SAR
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '60.00',
            'is_cash_in' => true,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        // Target updated to 50.00 SAR -> delta -10.00 SAR
        $this->balanceService->reconcileCashCollected(
            $this->cashierShift,
            oldCashCollectedHalalas: 6000,
            newCashCollectedHalalas: 5000,
            operationId: (string) Str::uuid()
        );

        $transactions = CashierCustodyTransaction::where('related_shift_id', $this->cashierShift->id)
            ->where('transaction_type', 'Total Sales')
            ->get();

        $this->assertCount(2, $transactions);
        $delta = $transactions->last();
        $this->assertSame('10.00', (string) $delta->amount);
        $this->assertFalse((bool) $delta->is_cash_in);
    }

    public function test_zero_delta_creates_no_new_transaction(): void
    {
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '60.00',
            'is_cash_in' => true,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        $this->balanceService->reconcileCashCollected(
            $this->cashierShift,
            oldCashCollectedHalalas: 6000,
            newCashCollectedHalalas: 6000,
            operationId: (string) Str::uuid()
        );

        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $this->cashierShift->id)->count());
    }

    public function test_conflicting_cashier_provenance_throws_balance_evidence_unavailable(): void
    {
        $otherCashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        // Malformed entry with different cashier
        CashierCustodyTransaction::create([
            'cashier_id' => $otherCashier->id,
            'transaction_type' => 'Total Sales',
            'amount' => '60.00',
            'is_cash_in' => true,
            'related_shift_id' => $this->cashierShift->id,
            'transaction_date' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('BALANCE_EVIDENCE_UNAVAILABLE');

        $this->balanceService->reconcileCashCollected(
            $this->cashierShift,
            oldCashCollectedHalalas: 6000,
            newCashCollectedHalalas: 7000,
            operationId: (string) Str::uuid()
        );
    }
}
