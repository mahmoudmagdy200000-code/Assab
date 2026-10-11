<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Models\ShiftVarianceDetail;
use Tests\TestCase;

class ShiftReportHistoryPreservationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $otherCashier;

    private Shift $shiftTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'S1-11 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = \Modules\Admin\Models\AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->sender = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->otherCashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function createShift(): CashierShift
    {
        return CashierShift::factory()->create([
            'cashier_id' => $this->sender->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(4),
            'opening_balance' => 0,
        ]);
    }

    /**
     * Test Case 1: Cash 400 + App 100, then Cash 500 + empty apps.
     * Current apps_halalas = 0, expected/count = 500, variance = 0, App 100 preserved in historical snapshot.
     */
    public function test_aggregators_cleared_on_re_end_and_preserved_in_snapshot(): void
    {
        $agg = Aggregator::factory()->create(['name' => 'Jahez']);
        $shift = $this->createShift();

        // 1. Cash 400 + App 100
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
            'aggregators' => [
                ['aggregator_id' => $agg->id, 'amount' => 100.00, 'notes' => 'Old note'],
            ],
        ])->assertOk();

        $this->assertSame(100.0, (float) ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->sum('amount'));

        // Reject
        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Fix breakdown']
        )->assertOk();

        // 2. Cash 500 + Empty apps
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
            'aggregators' => [],
        ])->assertOk();

        // Current apps = 0
        $this->assertSame(0.0, (float) ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->sum('amount'));

        // Old app 100 is preserved in the first snapshot
        $snapshots = ShiftReportRevisionSnapshot::orderBy('created_at')->get();
        $this->assertGreaterThanOrEqual(2, $snapshots->count());

        $firstSnapshotSales = $snapshots->first()->snapshot_data['sales_breakdown'];
        $this->assertCount(1, $firstSnapshotSales);
        $this->assertSame('100.00', $firstSnapshotSales[0]['amount']);
        $this->assertSame('Old note', $firstSnapshotSales[0]['notes']);
    }

    /**
     * Test Case 2: Aggregator 100 then 200 does not sum both revisions in current projection.
     */
    public function test_aggregator_amount_updated_does_not_sum_both_revisions(): void
    {
        $agg = Aggregator::factory()->create(['name' => 'Jahez']);
        $shift = $this->createShift();

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
            'aggregators' => [
                ['aggregator_id' => $agg->id, 'amount' => 100.00],
            ],
        ])->assertOk();

        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Fix Jahez amount']
        )->assertOk();

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '300.00',
            'counted_cash' => '300.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '300.00',
            'aggregators' => [
                ['aggregator_id' => $agg->id, 'amount' => 200.00],
            ],
        ])->assertOk();

        // Current sum is exactly 200, not 300
        $this->assertSame(200.0, (float) ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->sum('amount'));
    }

    /**
     * Test Case 3: Variance assignees A=60 and B=40, then A=100.
     * Current responsibility sum = 100, B is historical, old 60/40 distribution preserved in snapshot.
     */
    public function test_variance_assignee_change_excludes_old_assignee_from_current(): void
    {
        $shift = $this->createShift();

        // Submission with shared variance: total variance 100, self = 60, other = 40
        $res = $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->sender->id, 'amount' => '60.00'],
                ['responsible_type' => 'cashier', 'responsible_id' => $this->otherCashier->id, 'amount' => '40.00'],
            ],
            'allocation_reason' => 'Shared drawer shortage',
            'variance' => [
                'responsibility_type' => 'self_and_others',
                'current_cashier_amount' => '60.00',
                'other_cashiers' => [
                    ['cashier_id' => $this->otherCashier->id, 'amount' => '40.00', 'notes' => 'B share'],
                ],
                'reason' => 'Shared drawer shortage',
            ],
        ])->assertOk();

        $this->assertSame(100.0, (float) ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->sum('assigned_amount'));
        $this->assertSame(2, ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->count());

        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'I was solely responsible']
        )->assertOk();

        // Re-end with self only: total variance 100, self = 100, other cashier removed
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->sender->id, 'amount' => '100.00'],
            ],
            'allocation_reason' => 'Accepting full responsibility',
            'variance' => [
                'responsibility_type' => 'self',
                'reason' => 'Accepting full responsibility',
            ],
        ])->assertOk();

        // Current projection: only 1 row (self = 100), B is gone, sum = 100 (not 140)
        $this->assertSame(1, ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(100.0, (float) ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->sum('assigned_amount'));
        $this->assertDatabaseMissing('shift_variance_details', [
            'cashier_shift_id' => $shift->id,
            'responsible_cashier_id' => $this->otherCashier->id,
        ]);

        // Historical snapshot preserves the 60/40 distribution
        $snapshots = ShiftReportRevisionSnapshot::orderBy('created_at')->get();
        $firstSnapshotDetails = $snapshots->first()->snapshot_data['variance_details'];
        $this->assertCount(2, $firstSnapshotDetails);
        $assignedAmounts = array_column($firstSnapshotDetails, 'assigned_amount');
        $this->assertContains('60.00', $assignedAmounts);
        $this->assertContains('40.00', $assignedAmounts);
    }

    /**
     * Test Case 4: Variance corrected to zero removes all current responsibility rows.
     */
    public function test_variance_corrected_to_zero_purges_current_variance_details(): void
    {
        $shift = $this->createShift();

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '400.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->sender->id, 'amount' => '100.00'],
            ],
            'allocation_reason' => 'Shortage',
            'variance' => [
                'responsibility_type' => 'self',
                'reason' => 'Shortage',
            ],
        ])->assertOk();

        $this->assertSame(1, ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->count());

        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Recount cash']
        )->assertOk();

        // Re-end with balanced cash (500), 0 variance
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
        ])->assertOk();

        // Current projection has 0 variance details
        $this->assertSame(0, ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->count());

        // Snapshot preserves the original variance
        $snapshots = ShiftReportRevisionSnapshot::orderBy('created_at')->get();
        $this->assertNotEmpty($snapshots->first()->snapshot_data['variance_details']);
    }

    /**
     * Test Case 5: Net custody sales impact follows 500 -> 500 -> 480 -> 520 -> 0 without duplication.
     */
    public function test_custody_net_sales_delta_tracking_without_duplication(): void
    {
        $shift = $this->createShift();

        // 1. Initial 500.00
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '500.00',
        ])->assertOk();

        $this->assertSame(500.0, (float) CashierCustodyTransaction::where('related_shift_id', $shift->id)->sum(DB::raw('CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END')));

        // Reject and re-end with same 500.00 -> net should remain 500.00 (no duplicate)
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", ['rejection_reason' => 'Notes'])->assertOk();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '500.00',
        ])->assertOk();

        $this->assertSame(500.0, (float) CashierCustodyTransaction::where('related_shift_id', $shift->id)->sum(DB::raw('CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END')));

        // Reject and re-end with 480.00 -> net should become 480.00
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", ['rejection_reason' => 'Notes'])->assertOk();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '480.00', 'cash_collected' => '480.00', 'counted_cash' => '480.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '480.00',
        ])->assertOk();

        $this->assertSame(480.0, (float) CashierCustodyTransaction::where('related_shift_id', $shift->id)->sum(DB::raw('CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END')));

        // Reject and re-end with 520.00 -> net should become 520.00
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", ['rejection_reason' => 'Notes'])->assertOk();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '520.00', 'cash_collected' => '520.00', 'counted_cash' => '520.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '520.00',
        ])->assertOk();

        $this->assertSame(520.0, (float) CashierCustodyTransaction::where('related_shift_id', $shift->id)->sum(DB::raw('CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END')));

        // Reject and re-end with 0.00 -> net should become 0.00
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", ['rejection_reason' => 'Notes'])->assertOk();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '0.00', 'cash_collected' => '0.00', 'counted_cash' => '0.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '0.00',
        ])->assertOk();

        $this->assertSame(0.0, (float) CashierCustodyTransaction::where('related_shift_id', $shift->id)->sum(DB::raw('CASE WHEN is_cash_in = 1 THEN amount ELSE -amount END')));
    }
}
