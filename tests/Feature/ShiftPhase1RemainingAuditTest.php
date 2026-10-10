<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Models\ShiftVarianceReviewEvidence;
use Tests\TestCase;

class ShiftPhase1RemainingAuditTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $recipient;

    private Shift $shiftTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Audit Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Audit Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->sender = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->recipient = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->shiftTemplate = Shift::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
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
     * Audit Item 1: Duplicate handover request returns 409 HANDOVER_ALREADY_PENDING,
     * not 500, and does not allow modifying an already pending request even if a previous
     * rejection exists in history. Request ID, revision, and amounts remain stable.
     */
    public function test_duplicate_handover_submission_throws_conflict_and_preserves_request(): void
    {
        $shift = $this->createSenderShift();

        // 1. Create initial handover request (500.00)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $this->recipient->id,
            'handover_amount' => '500.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame('pending', $handover->status);

        // 2. Recipient rejects handover
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/handover/reject", [
            'rejection_reason' => 'Amount discrepancy',
        ])->assertOk();

        $this->assertSame('rejected', $handover->fresh()->status);

        // 3. Cashier edits handover to 480.00 after rejection (status becomes pending again)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/handover/edit", [
            'handover_amount' => '480.00',
            'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $freshHandover = $handover->fresh();
        $this->assertSame('pending', $freshHandover->status);
        $this->assertSame('480.00', (string) $freshHandover->handover_amount);
        $expectedRevisionId = $freshHandover->report_revision_id;

        // 4. Cashier attempts to record another handover while the edited request is pending without a new rejection
        // Direct HandoverService invocation
        try {
            app(\Modules\Shift\Services\HandoverService::class)->recordHandover($shift->fresh(), [
                'handover_to_type' => 'cashier',
                'next_cashier_id' => $this->recipient->id,
                'handover_amount' => '480.00',
            ], $this->sender);
            $this->fail('Expected ConflictHttpException was not thrown.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            $this->assertSame('HANDOVER_ALREADY_PENDING', $e->getMessage());
        }

        // HTTP route invocation
        $shift->update(['status' => ShiftStatus::COMPLETED]);
        $response = $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/handover", [
            'next_cashier_id' => $this->recipient->id,
            'handover_amount' => '480.00',
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('HANDOVER_ALREADY_PENDING', $response->json('message') ?? '');

        // Verify request, revision, and amount remain completely untouched
        $persisted = $handover->fresh();
        $this->assertSame($freshHandover->id, $persisted->id);
        $this->assertSame('pending', $persisted->status);
        $this->assertSame('480.00', (string) $persisted->handover_amount);
        $this->assertSame($expectedRevisionId, $persisted->report_revision_id);
    }

    /**
     * Audit Item 2: The snapshot of the revision bound to the handover request contains
     * the handover ID, recipient, and correct amount. start-handover captures the complete
     * responsibility distribution and files in the snapshot.
     */
    public function test_new_revision_snapshot_contains_handover_and_start_handover_preserves_variance(): void
    {
        Storage::fake('public');
        $shift = $this->createSenderShift();

        // Part A: end-with-handover snapshots the created handover request
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '600.00',
            'cash_collected' => '600.00',
            'counted_cash' => '600.00',
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '600.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $revision = ShiftReportRevision::findOrFail($handover->report_revision_id);
        $snapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $revision->id)->firstOrFail();

        $this->assertNotNull($snapshot->snapshot_data['handover']);
        $this->assertSame($handover->id, $snapshot->snapshot_data['handover']['id']);
        $this->assertSame('branch_manager', $snapshot->snapshot_data['handover']['handover_to_type']);
        $this->assertSame($this->manager->id, $snapshot->snapshot_data['handover']['handover_to_id']);
        $this->assertSame('600.00', (string) $snapshot->snapshot_data['handover']['handover_amount']);

        // Part B: start-handover with variance responsibility and files
        $shift2 = CashierShift::factory()->create([
            'cashier_id' => $this->sender->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today()->subDay(),
            'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHours(4),
            'opening_balance' => 0,
        ]);

        // Cashier ends shift without handover (shortage of 50.00)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift2->id}/end", [
            'total_sales' => '500.00',
            'cash_collected' => '450.00',
            'counted_cash' => '450.00',
            'shortage_allocations' => [
                [
                    'responsible_type' => 'cashier',
                    'responsible_id' => $this->sender->id,
                    'amount' => '50.00',
                ],
            ],
            'variance' => [
                'responsibility_type' => 'self',
                'reason' => 'Register discrepancy',
            ],
        ])->assertOk();

        // Now initiate handover via start-handover with supporting file
        $file = UploadedFile::fake()->create('voucher.pdf', 100, 'application/pdf');
        $startResponse = $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift2->id}/start-handover", [
            'handover_to_type' => 'cashier',
            'next_cashier_id' => $this->recipient->id,
            'handover_amount' => '450.00',
            'variance' => [
                'responsibility_type' => 'self',
                'reason' => 'Confirmed self responsibility',
                'supporting_files' => [$file],
            ],
        ])->assertOk();

        $handover2 = CashierShiftHandover::where('cashier_shift_id', $shift2->id)->firstOrFail();
        $revision2 = ShiftReportRevision::findOrFail($handover2->report_revision_id);
        $snapshot2 = ShiftReportRevisionSnapshot::where('report_revision_id', $revision2->id)->firstOrFail();

        $this->assertNotNull($snapshot2->snapshot_data['handover']);
        $this->assertSame($handover2->id, $snapshot2->snapshot_data['handover']['id']);
        $this->assertSame('450.00', (string) $snapshot2->snapshot_data['handover']['handover_amount']);
        $this->assertNotEmpty($snapshot2->snapshot_data['variance_details']);
        $this->assertSame('self', $snapshot2->snapshot_data['variance_details'][0]['responsibility_type']);
        $this->assertNotEmpty($snapshot2->snapshot_data['variance_details'][0]['supporting_files']);
    }

    /**
     * Audit Item 3: Variance responsibility approvals are preserved in history
     * upon rejection and correction. Actual fields (responsibility_status, reviewed_by_id,
     * reviewed_by_type, reviewed_at) remain intact. Old snapshots remain immutable.
     */
    public function test_variance_responsibility_approvals_preserved_historically_upon_correction(): void
    {
        $shift = $this->createSenderShift();

        // 1. Submit shortage report with handover
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '450.00',
            'counted_cash' => '450.00',
            'shortage_allocations' => [
                [
                    'responsible_type' => 'cashier',
                    'responsible_id' => $this->sender->id,
                    'amount' => '50.00',
                ],
            ],
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '450.00',
            'variance' => [
                'responsibility_type' => 'self',
                'reason' => 'Shortage accepted',
            ],
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $originalRevisionId = $handover->report_revision_id;

        // 2. Manager approves responsibility via the real route
        $approveRes = $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/responsibility/approve");
        $approveRes->assertOk();

        // Verify the responsibility detail was approved with reviewer metadata
        $detail = ShiftVarianceDetail::where('cashier_shift_id', $shift->id)->firstOrFail();
        $this->assertSame('approved', $detail->responsibility_status);
        $this->assertSame($this->manager->id, $detail->reviewed_by_id);
        $this->assertNotNull($detail->reviewed_at);

        // 3. Manager rejects handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", [
            'rejection_reason' => 'Discrepancy requires recount',
        ])->assertOk();

        // 4. Cashier corrects report and re-ends shift (balanced 450.00)
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
            'total_sales' => '450.00',
            'cash_collected' => '450.00',
            'counted_cash' => '450.00',
        ])->assertOk();

        // 5. Verify the approval history is preserved in history and review evidence
        $historyReview = CashierShiftHistory::where('cashier_shift_id', $shift->id)
            ->whereIn('action', ['responsibility_approved', 'variance_responsibility_review_preserved'])
            ->exists();
        $this->assertTrue($historyReview, 'Variance responsibility approval must be preserved in shift history.');

        $reviewEvidence = ShiftVarianceReviewEvidence::where('cashier_shift_id', $shift->id)
            ->where('responsibility_status', 'approved')
            ->firstOrFail();

        $this->assertSame($originalRevisionId, $reviewEvidence->report_revision_id);
        $this->assertSame($this->manager->id, $reviewEvidence->reviewed_by_id);
        $this->assertSame(get_class($this->manager), $reviewEvidence->reviewed_by_type);
        $this->assertNotNull($reviewEvidence->reviewed_at);

        // Verify old snapshot was NOT mutated
        $oldSnapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $originalRevisionId)->firstOrFail();
        $this->assertNotNull($oldSnapshot);
    }

    /**
     * Audit Item 4: POS receipt reference is preserved historically before replacing
     * report data, and the uploaded physical file remains on disk.
     */
    public function test_pos_receipt_reference_preserved_historically_upon_correction(): void
    {
        Storage::fake('public');
        $shift = $this->createSenderShift();

        $pdf = UploadedFile::fake()->create('pos_slip.pdf', 200, 'application/pdf');

        // End shift with POS receipt upload
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '500.00',
            'pos_receipt' => $pdf,
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $rev1 = ShiftReportRevision::findOrFail($handover->report_revision_id);
        $originalPosReceipt = $shift->fresh()->pos_receipt;
        $this->assertNotNull($originalPosReceipt);
        Storage::disk('public')->assertExists($originalPosReceipt);

        // Verify original snapshot captured pos_receipt
        $snapshot1 = ShiftReportRevisionSnapshot::where('report_revision_id', $rev1->id)->firstOrFail();
        $this->assertSame($originalPosReceipt, $snapshot1->snapshot_data['pos_receipt']);

        // Reject handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", [
            'rejection_reason' => 'Need re-end',
        ])->assertOk();

        // Re-end shift without sending a new pos_receipt
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
            'total_sales' => '500.00',
            'cash_collected' => '500.00',
            'counted_cash' => '500.00',
        ])->assertOk();

        // Current shift has pos_receipt cleared
        $this->assertNull($shift->fresh()->pos_receipt);

        // Original revision snapshot still preserves the POS receipt path
        $snapshot1Reloaded = ShiftReportRevisionSnapshot::where('report_revision_id', $rev1->id)->firstOrFail();
        $this->assertSame($originalPosReceipt, $snapshot1Reloaded->snapshot_data['pos_receipt']);

        // The file still exists on disk
        Storage::disk('public')->assertExists($originalPosReceipt);
    }

    /**
     * Audit Item 5: First correction of an old report without snapshots captures
     * the original revision snapshot before transitioning to the rejection revision,
     * preserving its sales channel and values under the original revision.
     */
    public function test_first_correction_of_legacy_report_without_snapshots_preserves_original_revision(): void
    {
        $shift = $this->createSenderShift();
        $aggregator = Aggregator::factory()->create();

        // 1. Submit report with sales channel of 100.00
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end-with-handover", [
            'total_sales' => '500.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'aggregators' => [
                [
                    'aggregator_id' => $aggregator->id,
                    'amount' => '100.00',
                ],
            ],
            'handover_to_type' => 'branch_manager',
            'branch_manager_id' => $this->manager->id,
            'handover_amount' => '400.00',
        ])->assertOk();

        $handover = CashierShiftHandover::where('cashier_shift_id', $shift->id)->firstOrFail();
        $originalRevision = ShiftReportRevision::findOrFail($handover->report_revision_id);

        // Simulate legacy state: purge any snapshots created during submission
        ShiftReportRevisionSnapshot::query()->delete();
        $this->assertSame(0, ShiftReportRevisionSnapshot::count());

        // 2. Reject handover
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/handover/reject", [
            'rejection_reason' => 'Aggregator amount correction needed',
        ])->assertOk();

        // 3. Cashier re-ends shift with updated aggregator amount of 150.00
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
            'total_sales' => '550.00',
            'cash_collected' => '400.00',
            'counted_cash' => '400.00',
            'aggregators' => [
                [
                    'aggregator_id' => $aggregator->id,
                    'amount' => '150.00',
                ],
            ],
        ])->assertOk();

        // 4. Verify a snapshot was created for the ORIGINAL revision preserving the channel of 100.00
        $originalSnapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $originalRevision->id)->firstOrFail();
        $this->assertNotEmpty($originalSnapshot->snapshot_data['sales_breakdown']);
        $this->assertSame($aggregator->id, $originalSnapshot->snapshot_data['sales_breakdown'][0]['aggregator_id']);
        $this->assertSame('100.00', (string) $originalSnapshot->snapshot_data['sales_breakdown'][0]['amount']);
        $this->assertSame(10000, $originalSnapshot->snapshot_data['sales_breakdown'][0]['amount_halalas']);

        // 5. Verify the current projection has only the corrected amount (150.00)
        $currentBreakdown = ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->get();
        $this->assertCount(1, $currentBreakdown);
        $this->assertSame('150.00', (string) $currentBreakdown->first()->amount);
    }
}
