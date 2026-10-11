<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Tests\TestCase;

class ShiftAuditFindingsReproductionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $recipientA;

    private Cashier $recipientB;

    private Shift $shiftTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->sender = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->recipientA = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->recipientB = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->shiftTemplate = Shift::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function createSenderShift(string $status = ShiftStatus::IN_PROGRESS->value): CashierShift
    {
        return CashierShift::factory()->create([
            'cashier_id' => $this->sender->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => $status,
            'actual_start_time' => now()->subHours(4),
            'opening_balance' => 0,
        ]);
    }

    /**
     * Finding 1: Plain rejection of request with physical transfer attempt
     * MUST require physical details and reject with 409 PHYSICAL_REJECTION_DETAILS_REQUIRED,
     * without creating or auto-confirming any ShiftTransferReturn.
     */
    public function test_plain_rejection_of_typed_request_requires_physical_details(): void
    {
        $shift = $this->createSenderShift();

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();

        // Present physical attempt
        $presentRes = $this->actingAs($this->sender, 'sanctum')->postJson(
            "/api/v1/shift-transfers/handover/{$handover->id}/present",
            ['presented_halalas' => 50000, 'idempotency_key' => 'attempt-rep-1']
        )->assertOk();

        $attemptId = $presentRes->json('data.id');
        $this->assertNotNull($attemptId);
        $this->assertSame($attemptId, $handover->fresh()->current_transfer_attempt_id);

        // Attempting plain rejection without structured physical details must return 409 PHYSICAL_REJECTION_DETAILS_REQUIRED
        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Discrepancy noted']
        );

        $response->assertStatus(409);
        $this->assertSame('PHYSICAL_REJECTION_DETAILS_REQUIRED', $response->json('code'));

        // No returns should have been created or auto-confirmed
        $this->assertDatabaseCount('shift_transfer_returns', 0);
        $this->assertDatabaseCount('shift_transfer_rejection_evidence', 0);
        $this->assertSame('pending', $handover->fresh()->status);
    }

    /**
     * Finding 2: History preservation vs current projection.
     * When aggregators or variance assignees are removed/replaced in a re-ended shift,
     * historical snapshots must preserve the old data, while the current projection
     * must ONLY contain the current revision's data (removed aggregators/assignees excluded).
     */
    public function test_historical_revisions_preserved_and_removed_aggregators_excluded_from_current(): void
    {
        $aggA = Aggregator::factory()->create(['name' => 'Jahez']);
        $aggB = Aggregator::factory()->create(['name' => 'HungerStation']);

        $shift = $this->createSenderShift();

        // 1. First submission: Aggregator A = 400, Aggregator B = 100, Total = 500
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '0.00',
            'counted_cash' => '0.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '0.00',
            'aggregators' => [
                ['aggregator_id' => $aggA->id, 'amount' => 400.00],
                ['aggregator_id' => $aggB->id, 'amount' => 100.00],
            ],
        ])->assertOk();

        $this->assertSame(500.0, (float) ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->sum('amount'));

        // Manager rejects handover (no physical attempt exists)
        $this->actingAs($this->manager, 'sanctum')->postJson(
            "/api/v1/branch-manager/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Aggregator breakdown incorrect']
        )->assertOk();

        // 2. Second submission: Aggregator A = 500, Aggregator B REMOVED entirely
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '0.00',
            'counted_cash' => '0.00',
            'handover_to_type' => 'branch_manager',
            'handover_amount' => '0.00',
            'aggregators' => [
                ['aggregator_id' => $aggA->id, 'amount' => 500.00],
            ],
        ])->assertOk();

        // Current sales breakdown projection MUST NOT include removed Aggregator B
        $currentSum = (float) ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->sum('amount');
        $this->assertSame(500.0, $currentSum);
        $this->assertDatabaseMissing('shift_sales_breakdown', [
            'cashier_shift_id' => $shift->id,
            'aggregator_id' => $aggB->id,
        ]);

        // Historical snapshots must exist
        $snapshots = \Modules\Shift\Models\ShiftReportRevisionSnapshot::orderBy('created_at')->get();
        $this->assertGreaterThanOrEqual(2, $snapshots->count());

        // First snapshot has Aggregator B (100) preserved
        $firstSnapshotData = $snapshots->first()->snapshot_data;
        $this->assertNotEmpty($firstSnapshotData['sales_breakdown']);
        $aggregatorIdsInFirst = array_column($firstSnapshotData['sales_breakdown'], 'aggregator_id');
        $this->assertContains($aggB->id, $aggregatorIdsInFirst);

        // Latest snapshot does NOT have Aggregator B
        $latestSnapshotData = $snapshots->last()->snapshot_data;
        $aggregatorIdsInLatest = array_column($latestSnapshotData['sales_breakdown'], 'aggregator_id');
        $this->assertNotContains($aggB->id, $aggregatorIdsInLatest);
    }

    /**
     * Finding 3: Changing recipient on the same handover request MUST throw 409
     * HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT.
     */
    public function test_changing_recipient_on_same_handover_throws_conflict(): void
    {
        $shift = $this->createSenderShift();

        // Initial submission targeting Recipient A
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $this->recipientA->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame($this->recipientA->id, $handover->handover_to_id);

        // Recipient A rejects
        $this->actingAs($this->recipientA, 'sanctum')->postJson(
            "/api/v1/cashier/shifts/{$shift->id}/handover/reject",
            ['rejection_reason' => 'Wrong recipient selected']
        )->assertOk();

        // Now sender tries to end-with-handover targeting Recipient B or Branch Manager on the same handover
        $response = $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $this->recipientB->id,
            'handover_amount' => '500.00',
        ]);

        $response->assertStatus(409);
        $this->assertSame('HANDOVER_RECIPIENT_CHANGE_REQUIRES_REPLACEMENT', $response->json('code'));
    }
}
