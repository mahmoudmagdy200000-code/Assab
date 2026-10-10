<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftReportCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private Branch $branch;

    private Cashier $cashier;

    private Shift $shift;

    private CashierShift $cashierShift;

    private ShiftReportRevisionService $revisionService;

    private ShiftCashCountService $countService;

    private ShiftReportCorrectionService $correctionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->companyId,
        ]);
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->shift = Shift::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->cashierShift = CashierShift::factory()->create([
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier->id,
            'total_sales' => '115.00',
            'net_sales' => '100.00',
            'vat_amount' => '15.00',
            'card_payments' => '20.00',
            'cash_collected' => '90.00',
            'status' => 'in_progress',
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $this->countService = app(ShiftCashCountService::class);
        $this->correctionService = app(ShiftReportCorrectionService::class);
    }

    public function test_correct_cashier_report_exact_arithmetic_gross_115_and_card_20_01(): void
    {
        // Setup initial revision 1 and initial count evidence
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $aggregator = Aggregator::factory()->create();
        ShiftSalesBreakdown::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'aggregator_id' => $aggregator->id,
            'amount' => '5.00',
        ]);

        \Illuminate\Support\Facades\DB::table('cashier_shift_handover_receipts')->insert([
            'id' => (string) Str::uuid(),
            'receiving_cashier_shift_id' => $this->cashierShift->id,
            'receiving_cashier_id' => $this->cashier->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '10.00',
            'confirmed_by_id' => $this->cashier->id,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Count 100.00 SAR with opening 10.00 SAR, cards 20.00 SAR, apps 5.00 SAR
        // gross = 11500 halalas, cards = 2000, apps = 500, opening = 1000, count = 10000
        // expected = 11500 - 2000 - 500 + 1000 = 10000 (100.00 SAR), variance = 0
        $initialCount = ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 11500,
            'cards_halalas' => 2000,
            'apps_halalas' => 500,
            'confirmed_opening_halalas' => 1000,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 10000,
            'expected_halalas' => 10000,
            'variance_halalas' => 0,
        ]);

        // Correction: card payments changed from 20.00 to 20.01 SAR
        $opId = (string) Str::uuid();
        $rev2 = $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '20.01',
            ],
            expectedRevision: 1,
            reason: 'Audit adjustment on card receipts',
            operationId: $opId
        );

        $this->assertEquals(2, $rev2->revision_number);

        // Verify count evidence for revision 2:
        $count2 = ShiftReportCashCount::where('report_revision_id', $rev2->id)->first();
        $this->assertNotNull($count2);
        $this->assertEquals($rev1->id, $count2->counted_revision_id, 'Original physical count observation must be preserved');
        $this->assertEquals($rev2->id, $count2->evidence_revision_id, 'Evidence revision must be the new revision');
        $this->assertEquals(11500, $count2->gross_halalas);
        $this->assertEquals(2001, $count2->cards_halalas);
        $this->assertEquals(500, $count2->apps_halalas);
        $this->assertEquals(1000, $count2->confirmed_opening_halalas);
        $this->assertEquals(10000, $count2->counted_halalas);
        // expected = 11500 - 2001 - 500 + 1000 = 9999 halalas
        $this->assertEquals(9999, $count2->expected_halalas);
        // variance = 10000 - 9999 = +1 halala (surplus of 0.01 SAR)
        $this->assertEquals(1, $count2->variance_halalas);

        // Verify projection updated on cashier_shifts
        $fresh = $this->cashierShift->fresh();
        $this->assertEquals('20.01', (string) $fresh->card_payments);
        $this->assertEquals('100.00', (string) $fresh->net_sales);
        $this->assertEquals('15.00', (string) $fresh->vat_amount);

        // Verify correction audit rows in shift_report_corrections
        $correction = ShiftReportCorrection::where('report_aggregate_id', $rev2->report_aggregate_id)
            ->where('field_name', 'card_payments')
            ->first();
        $this->assertNotNull($correction);
        $this->assertEquals('monetary', $correction->field_type);
        $this->assertEquals('20.00', $correction->old_value);
        $this->assertEquals('20.01', $correction->new_value);
        $this->assertEquals(2000, $correction->old_halalas);
        $this->assertEquals(2001, $correction->new_halalas);
        $this->assertEquals($opId, $correction->operation_id);
    }

    public function test_no_report_changes_throws_422(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ValidationException::class);
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'total_sales' => '115.00', // same as current
                'card_payments' => '20.00', // same as current
            ],
            expectedRevision: 1,
            reason: 'Redundant correction',
            operationId: (string) Str::uuid()
        );
    }

    public function test_stale_expected_revision_throws_409(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ConflictHttpException::class);
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '25.00',
            ],
            expectedRevision: 99, // Stale
            reason: 'Audit adjustment',
            operationId: (string) Str::uuid()
        );
    }

    public function test_empty_reason_throws_validation_exception(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ValidationException::class);
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '25.00',
            ],
            expectedRevision: 1,
            reason: '   ', // empty
            operationId: (string) Str::uuid()
        );
    }

    public function test_cards_and_apps_exceeding_gross_throws_validation_exception(): void
    {
        $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $this->expectException(ValidationException::class);
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '200.00', // exceeds gross 115.00
            ],
            expectedRevision: 1,
            reason: 'Invalid card payments',
            operationId: (string) Str::uuid()
        );
    }
}
