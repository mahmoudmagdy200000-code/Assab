<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Liability\CashCountLiabilityEvidence;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftReportCorrectionEvidenceTest extends TestCase
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
            'status' => 'completed',
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $this->countService = app(ShiftCashCountService::class);
        $this->correctionService = app(ShiftReportCorrectionService::class);
    }

    public function test_old_snapshot_preserved_and_liability_allocation_superseded(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        $count1 = ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 11500,
            'cards_halalas' => 2000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 9000,
            'expected_halalas' => 9500,
            'variance_halalas' => -500,
        ]);

        // Create liability allocation linked to revision 1 evidence
        $allocation = ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'company_id' => $this->companyId,
            'branch_id' => $this->branch->id,
            'version' => 1,
            'report_revision' => (string) $rev1->id,
            'variance_halalas' => -500,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
            'manager_approval_status' => 'approved',
            'manager_approved_by' => (string) Str::uuid(),
            'manager_approved_at' => now(),
        ]);

        // Correction in revision 2
        $rev2 = $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '25.00',
            ],
            expectedRevision: 1,
            reason: 'Correction of cards',
            operationId: (string) Str::uuid()
        );

        // Check snapshot was created for rev1 and rev2
        $snapshot1 = ShiftReportRevisionSnapshot::where('report_revision_id', $rev1->id)->first();
        $this->assertNotNull($snapshot1, 'Snapshot for revision 1 must exist');
        $this->assertEquals('20.00', $snapshot1->snapshot_data['card_payments']);

        $snapshot2 = ShiftReportRevisionSnapshot::where('report_revision_id', $rev2->id)->first();
        $this->assertNotNull($snapshot2, 'Snapshot for revision 2 must exist');
        $this->assertEquals('25.00', $snapshot2->snapshot_data['card_payments']);

        // Check liability allocation was superseded
        $freshAlloc = $allocation->fresh();
        $this->assertNotNull($freshAlloc->superseded_at, 'Old allocation must be marked superseded');
        $this->assertEquals('approved', $freshAlloc->manager_approval_status, 'Approval actor/time must be preserved');

        // Check liability evidence source returns revision 2 evidence id
        $evidenceSource = app(CashCountLiabilityEvidence::class);
        $evidence = $evidenceSource->report($this->cashierShift->id);
        $this->assertEquals((string) $rev2->id, $evidence->revision);
    }

    public function test_recount_required_flag_blocks_correction_helper(): void
    {
        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);

        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 11500,
            'cards_halalas' => 2000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 9500,
            'expected_halalas' => 9500,
            'variance_halalas' => 0,
        ]);

        // Set fresh_count_required = true on aggregate
        \Illuminate\Support\Facades\DB::table('shift_report_aggregates')
            ->where('id', $rev1->report_aggregate_id)
            ->update(['fresh_count_required' => true]);

        $this->expectException(ConflictHttpException::class);
        $this->correctionService->correctCashierReport(
            $this->cashierShift,
            $this->cashier,
            [
                'card_payments' => '25.00',
            ],
            expectedRevision: 1,
            reason: 'Correction when physical recount is needed',
            operationId: (string) Str::uuid()
        );
    }
}
