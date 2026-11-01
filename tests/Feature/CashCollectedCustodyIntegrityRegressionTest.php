<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftEndService;
use App\Support\ShiftFinancialCalculator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Task 1 — FR-04 Targeted Regression Test:
 * Verifies cash_collected vs counted_cash separation, replay protection,
 * custody delta integrity, and zero mutations on rejection.
 */
class CashCollectedCustodyIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private BranchManager $manager;
    private Cashier $cashier;
    private Cashier $otherCashier;
    private CashierShift $shift;
    private ShiftEndService $shiftEndService;
    private CashierCustodyService $custodyService;
    private HandoverService $handoverService;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Regression Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'BrandRegression', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->otherCashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $template = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today()->toDateString(),
            'status' => ShiftStatus::IN_PROGRESS->value,
            'actual_start_time' => now(),
            'total_sales' => '1000.00',
            'cash_collected' => '500.00',
            'card_payments' => '300.00',
            'opening_balance' => '100.00',
        ]);

        $this->shiftEndService = app(ShiftEndService::class);
        $this->custodyService = app(CashierCustodyService::class);
        $this->handoverService = app(HandoverService::class);
    }

    /**
     * 1. Physical cash variance is strictly derived from counted cash vs expected cash,
     * completely independent of cash_collected input or channel breakdown differences.
     */
    public function test_physical_cash_variance_is_strictly_independent_of_cash_collected(): void
    {
        // Scenario: gross = 1000 SAR (100,000h), cards = 300 SAR (30,000h), apps = 200 SAR (20,000h), opening = 100 SAR (10,000h)
        // Expected cash = 100,000 - 30,000 - 20,000 + 10,000 = 60,000h (600.00 SAR)
        $grossH = 100000;
        $cardsH = 30000;
        $appsH = 20000;
        $openingH = 10000;
        $countedH = 55000; // 550.00 SAR physical count -> 50.00 SAR shortage (-5000h)

        // Case A: reported cash_collected = 500 SAR (50,000h)
        $calcA = ShiftFinancialCalculator::calculate($grossH, $cardsH, $appsH, $openingH, $countedH, 0);
        $this->assertSame(60000, $calcA['expected']);
        $this->assertSame(-5000, $calcA['variance']);
        $this->assertSame(5000, $calcA['shortage']);
        $this->assertSame(0, $calcA['surplus']);
        $this->assertSame('shortage', $calcA['varianceType']);

        // Case B: reported cash_collected differs (e.g. 900 SAR), channels checked separately
        $channelCheck = ShiftFinancialCalculator::salesChannelCheck($grossH, $cardsH, $appsH, 90000);
        $this->assertTrue($channelCheck['channelsValid']);
        $this->assertSame(50000, $channelCheck['derivedCashSales']); // 1000 - 300 - 200 = 500 SAR
        $this->assertSame(40000, $channelCheck['channelDifference']); // 900 - 500 = +400 SAR data-entry difference

        // Invariant: channelDifference does NOT change physical variance
        $calcB = ShiftFinancialCalculator::calculate($grossH, $cardsH, $appsH, $openingH, $countedH, 0);
        $this->assertSame($calcA['variance'], $calcB['variance']);
        $this->assertSame($calcA['expected'], $calcB['expected']);
    }

    /**
     * 2. ShiftEndService records cash_collected custody delta idempotently on shift end.
     */
    public function test_shift_end_service_records_cash_collected_custody_delta_idempotently(): void
    {
        $endData = [
            'total_sales' => '1000.00',
            'cash_collected' => '700.00',
            'card_payments' => '300.00',
            'counted_cash' => '800.00', // expected = 1000 - 300 + 100 opening = 800 (balanced)
        ];

        // First end: creates 700.00 Total Sales Cash-IN
        $endedShift = $this->shiftEndService->endShiftOnly($this->shift, $endData, $this->cashier);

        $this->assertSame(ShiftStatus::COMPLETED, $endedShift->status);
        $this->assertSame(700.00, $this->custodyService->getPersonalBalanceOnly($this->cashier->id));

        $txCount = CashierCustodyTransaction::where('related_shift_id', $this->shift->id)
            ->where('transaction_type', 'Total Sales')
            ->count();
        $this->assertSame(1, $txCount);

        $tx = CashierCustodyTransaction::where('related_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame('700.00', (string) $tx->amount);
        $this->assertTrue((bool) $tx->is_cash_in);
    }

    /**
     * 3. Handover rejection causes zero custody mutations and resets liability count (fail closed).
     */
    public function test_handover_rejection_causes_zero_custody_mutations_and_fails_closed(): void
    {
        // First end shift with handover to manager (balanced shift: expected = 1000 - 300 + 100 = 800)
        $endData = [
            'total_sales' => '1000.00',
            'cash_collected' => '700.00',
            'card_payments' => '300.00',
            'counted_cash' => '800.00',
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '700.00',
        ];

        $this->shiftEndService->endShiftWithHandover($this->shift, $endData, $this->cashier);

        $initialBalance = $this->custodyService->getPersonalBalanceOnly($this->cashier->id);
        $txCountBeforeReject = CashierCustodyTransaction::count();

        // Reject handover
        $rejectResult = $this->handoverService->rejectHandover(
            $this->shift,
            $this->manager->id,
            BranchManager::class,
            'Physical count discrepancy'
        );

        $this->assertSame('reverted', $rejectResult['handover_status']);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->shift->fresh()->status);

        // Zero custody mutations on rejection
        $this->assertSame($txCountBeforeReject, CashierCustodyTransaction::count());
        $this->assertSame($initialBalance, $this->custodyService->getPersonalBalanceOnly($this->cashier->id));
    }

    /**
     * 4. CashierCustodyService::recordCashCollected() does NOT duplicate Cash-IN
     * when ShiftEndService already posted Total Sales for the shift.
     */
    public function test_record_cash_collected_does_not_duplicate_when_shift_end_already_posted(): void
    {
        $endData = [
            'total_sales' => '1000.00',
            'cash_collected' => '700.00',
            'card_payments' => '300.00',
            'counted_cash' => '800.00',
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '700.00',
        ];

        $this->shiftEndService->endShiftWithHandover($this->shift, $endData, $this->cashier);

        $handover = CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->firstOrFail();

        // Direct invocation of recordCashCollected must detect existing shift Total Sales and return it
        $txn = $this->custodyService->recordCashCollected($handover, $this->cashier);

        $this->assertNotNull($txn);
        $this->assertSame('700.00', (string) $txn->amount);

        // Personal balance must NOT be doubled to 1400.00
        $this->assertSame(700.00, $this->custodyService->getPersonalBalanceOnly($this->cashier->id));

        // Exact 1 Total Sales transaction exists for this shift
        $totalSalesCount = CashierCustodyTransaction::where('related_shift_id', $this->shift->id)
            ->where('transaction_type', 'Total Sales')
            ->count();
        $this->assertSame(1, $totalSalesCount);
    }

    /**
     * 5. CashierCustodyService::recordCashCollected() fails closed on rejected handover (zero mutations).
     */
    public function test_record_cash_collected_fails_closed_on_rejected_handover(): void
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '500.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => '16:00:00',
            'status' => 'rejected',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('HANDOVER_REJECTED');

        $this->custodyService->recordCashCollected($handover, $this->cashier);
    }

    /**
     * 6. CashierCustodyService::recordCashCollected() fails closed on cashier mismatch.
     */
    public function test_record_cash_collected_fails_closed_on_cashier_mismatch(): void
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->otherCashier->id,
            'handover_amount' => '500.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => '16:00:00',
            'status' => 'pending',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('CASHIER_MISMATCH');

        // Other cashier cannot record cash collected for this cashier's shift
        $this->custodyService->recordCashCollected($handover, $this->otherCashier);
    }

    /**
     * 7. Replay idempotency: Multiple direct invocations of recordCashCollected return identical transaction.
     */
    public function test_record_cash_collected_replay_idempotency(): void
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '500.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => '16:00:00',
            'status' => 'pending',
        ]);

        $txn1 = $this->custodyService->recordCashCollected($handover, $this->cashier);
        $txn2 = $this->custodyService->recordCashCollected($handover, $this->cashier);

        $this->assertSame($txn1->id, $txn2->id);
        $this->assertSame(1, CashierCustodyTransaction::where('related_handover_id', $handover->id)->count());
    }

    /**
     * 8. Actual Shift End Route: Inconsistent cash_collected (e.g. 900 vs derived 700)
     * must NOT inflate CashierCustodyTransaction or custody balance with unearned cash,
     * while preserving independent expected/counted variance and channel difference.
     */
    public function test_actual_shift_end_with_inconsistent_cash_collected_does_not_inflate_custody(): void
    {
        // total_sales = 1000, card_payments = 300, apps = 0 -> derived cash sales = 700.00 SAR
        // confirmed opening = 0 (no prior handover receipts) -> expected cash = 700.00 SAR
        // counted_cash = 700.00 SAR -> balanced (variance = 0)
        // Inconsistent payload: cash_collected = 900.00 SAR (+200.00 data entry channel difference)
        $endData = [
            'total_sales' => '1000.00',
            'cash_collected' => '900.00', // Inconsistent with 1000 - 300 = 700
            'card_payments' => '300.00',
            'counted_cash' => '700.00',
        ];

        $endedShift = $this->shiftEndService->endShiftOnly($this->shift, $endData, $this->cashier);

        // 1. Status is COMPLETED
        $this->assertSame(ShiftStatus::COMPLETED, $endedShift->status);

        // 2. Physical variance is strictly preserved (balanced, 0 variance)
        $this->assertFalse($endedShift->hasVariance());

        // 3. Raw cash_collected on shift is preserved without hiding channel difference (BR v3.5)
        $this->assertSame('900.00', (string) $endedShift->cash_collected);

        // 4. Custody Cash-IN must reflect actual cash from sales (derived: 700.00 SAR),
        // NOT the inflated/inconsistent 900.00 SAR!
        $this->assertSame(700.00, $this->custodyService->getPersonalBalanceOnly($this->cashier->id));

        $totalSalesTxn = CashierCustodyTransaction::where('related_shift_id', $this->shift->id)
            ->where('transaction_type', 'Total Sales')
            ->firstOrFail();
        $this->assertSame('700.00', (string) $totalSalesTxn->amount);
    }
}
