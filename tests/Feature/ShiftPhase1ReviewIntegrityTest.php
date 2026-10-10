<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Models\ShiftVarianceReviewEvidence;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftReportRevisionSnapshotService;
use Tests\TestCase;

class ShiftPhase1ReviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $company = AsabCompany::create(['name' => 'Review audit', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Review brand', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $this->shift = CashierShift::factory()->create(['cashier_id' => $this->cashier->id, 'shift_id' => $template->id, 'status' => ShiftStatus::IN_PROGRESS, 'shift_date' => today(), 'opening_balance' => 0, 'actual_start_time' => now()->subHours(4)]);
    }

    private function submitShortage(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '450.00', 'counted_cash' => '450.00',
            'handover_to_type' => 'branch_manager', 'branch_manager_id' => $this->manager->id, 'handover_amount' => '450.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id, 'amount' => '50.00']],
            'variance' => ['responsibility_type' => 'self', 'reason' => 'Register shortage'],
        ])->assertOk();
    }

    public function test_cashier_rejection_returns_success_and_preserves_review(): void
    {
        $this->submitShortage();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/my-shifts/{$this->shift->id}/responsibility/reject", ['reason' => 'Disputed assignment'])->assertOk();
        $this->assertDatabaseHas('shift_variance_review_evidence', ['cashier_shift_id' => $this->shift->id, 'responsibility_status' => 'rejected', 'reviewed_by_id' => $this->cashier->id, 'rejection_reason' => 'Disputed assignment']);
    }

    public function test_manager_review_failure_rolls_back_responsibility_and_history(): void
    {
        $this->assertReviewFailureRollsBack('branch-manager', $this->manager);
    }

    public function test_cashier_review_failure_rolls_back_responsibility_and_history(): void
    {
        $this->assertReviewFailureRollsBack('cashier/my-shifts', $this->cashier);
    }

    private function assertReviewFailureRollsBack(string $prefix, Cashier|BranchManager $actor): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure injection uses an SQLite trigger.');
        }
        $this->submitShortage();
        $historyCount = CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->count();
        DB::statement("CREATE TRIGGER fail_review_insert BEFORE INSERT ON shift_variance_review_evidence BEGIN SELECT RAISE(ABORT, 'injected review failure'); END");
        $path = $prefix === 'branch-manager' ? 'branch-manager/shifts' : $prefix;
        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/{$path}/{$this->shift->id}/responsibility/reject", ['reason' => 'Disputed assignment'])->assertStatus(500);
        $detail = ShiftVarianceDetail::where('cashier_shift_id', $this->shift->id)->firstOrFail();
        $this->assertSame('pending', $detail->responsibility_status);
        $this->assertNull($detail->reviewed_by_id);
        $this->assertSame($historyCount, CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->count());
        $this->assertSame(0, ShiftVarianceReviewEvidence::count());
    }

    public function test_each_distinct_review_event_is_preserved_even_with_identical_timestamp(): void
    {
        $this->submitShortage();
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($this->shift);
        $this->freezeTime();
        $path = "/api/v1/branch-manager/shifts/{$this->shift->id}/responsibility";
        $this->actingAs($this->manager, 'sanctum')->postJson("{$path}/approve")->assertOk();
        $this->postJson("{$path}/reject", ['reason' => 'Revised review'])->assertOk();
        $this->postJson("{$path}/approve")->assertOk();
        $reviews = ShiftVarianceReviewEvidence::where('cashier_shift_id', $this->shift->id)->orderBy('id')->get();
        $this->assertSame(['approved', 'rejected', 'approved'], $reviews->pluck('responsibility_status')->all());
        $this->assertSame([$revision->id], $reviews->pluck('report_revision_id')->unique()->values()->all());
        app(ShiftReportRevisionSnapshotService::class)->preserveVarianceReviews($this->shift);
        $this->assertSame(3, ShiftVarianceReviewEvidence::count(), 'Preservation must not duplicate the latest review event.');
    }

    public function test_correction_does_not_copy_old_approval_into_current_revision(): void
    {
        $this->submitShortage();
        $original = app(ShiftReportRevisionService::class)->currentCashierRevision($this->shift);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/responsibility/approve")->assertOk();
        $this->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/handover/reject", ['rejection_reason' => 'Recount'])->assertOk();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end", ['total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00'])->assertOk();
        $current = app(ShiftReportRevisionService::class)->currentCashierRevision($this->shift);
        $this->assertSame(0, ShiftVarianceReviewEvidence::where('report_revision_id', $current->id)->count());
        $this->assertSame([$original->id], ShiftVarianceReviewEvidence::where('cashier_shift_id', $this->shift->id)->pluck('report_revision_id')->unique()->values()->all());
        $this->assertSame(0, ShiftVarianceDetail::where('cashier_shift_id', $this->shift->id)->count());
    }

    public function test_handover_snapshot_preserves_original_notes_after_correction(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager', 'branch_manager_id' => $this->manager->id, 'handover_amount' => '500.00', 'handover_notes' => 'Original handover note',
        ])->assertOk();
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->firstOrFail();
        $originalId = $handover->report_revision_id;
        $snapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $originalId)->firstOrFail();
        $this->assertSame('Original handover note', $snapshot->snapshot_data['handover']['handover_notes'] ?? null);
        $originalData = $snapshot->snapshot_data;
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/handover/reject", ['rejection_reason' => 'Recount'])->assertOk();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'handover_to_type' => 'branch_manager', 'branch_manager_id' => $this->manager->id, 'handover_amount' => '500.00', 'handover_notes' => 'Corrected handover note',
        ])->assertOk();
        $this->assertSame('Corrected handover note', $handover->fresh()->handover_notes);
        $this->assertSame($originalData, $snapshot->fresh()->snapshot_data);
    }

    public function test_legacy_snapshot_keeps_variance_files_reason_and_rejection_metadata(): void
    {
        Storage::fake('public');
        $this->submitShortage();
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->firstOrFail();
        $originalId = $handover->report_revision_id;
        $file = UploadedFile::fake()->create('legacy-voucher.pdf', 10, 'application/pdf')->store('audit', 'public');
        // Existing deployed rows can precede snapshot creation.
        ShiftReportRevisionSnapshot::query()->delete();
        $handover->update(['handover_notes' => 'Legacy note', 'variance_reason' => 'Original explanation', 'variance_files' => [$file], 'rejection_reason' => 'Earlier rejection', 'rejection_count' => 1, 'first_rejected_at' => now()->subHour()]);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/handover/reject", ['rejection_reason' => 'New recount'])->assertOk();
        $data = ShiftReportRevisionSnapshot::where('report_revision_id', $originalId)->firstOrFail()->snapshot_data['handover'];
        $this->assertSame('Legacy note', $data['handover_notes']);
        $this->assertSame('Original explanation', $data['variance_reason']);
        $this->assertSame([$file], $data['variance_files']);
        $this->assertSame('Earlier rejection', $data['rejection_reason']);
        $this->assertSame(1, $data['rejection_count']);
        $this->assertNotNull($data['first_rejected_at']);
        Storage::disk('public')->assertExists($file);
    }

    public function test_legacy_review_with_unknown_timestamp_is_preserved_once_without_inventing_time(): void
    {
        $this->submitShortage();
        ShiftVarianceDetail::where('cashier_shift_id', $this->shift->id)->update(['responsibility_status' => 'approved']);
        $service = app(ShiftReportRevisionSnapshotService::class);
        $service->preserveVarianceReviews($this->shift);
        $service->preserveVarianceReviews($this->shift);
        $this->assertSame(1, ShiftVarianceReviewEvidence::count());
        $this->assertNull(ShiftVarianceReviewEvidence::firstOrFail()->reviewed_at);
    }

    public function test_edit_retains_original_request_evidence_when_legacy_snapshot_is_incomplete(): void
    {
        $this->assertIncompleteSnapshotCorrectionPreservesEvidence(true);
    }

    public function test_resubmission_retains_original_request_evidence_when_legacy_snapshot_is_incomplete(): void
    {
        $this->assertIncompleteSnapshotCorrectionPreservesEvidence(false);
    }

    private function assertIncompleteSnapshotCorrectionPreservesEvidence(bool $edit): void
    {
        Storage::fake('public');
        $this->submitShortage();
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->firstOrFail();
        $file = UploadedFile::fake()->create('legacy-original.pdf', 10, 'application/pdf')->store('audit', 'public');
        $handover->update(['handover_notes' => 'Original request note', 'variance_reason' => 'Original explanation', 'variance_files' => [$file]]);
        $this->shift->update(['handover_notes' => 'Original report note']);
        $snapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $handover->report_revision_id)->firstOrFail();
        $legacyData = $snapshot->snapshot_data;
        foreach (['handover_notes', 'variance_reason', 'variance_files', 'rejection_reason'] as $field) {
            unset($legacyData['handover'][$field]);
        }
        unset($legacyData['handover_notes'], $legacyData['closing_balance']);
        // Simulate the immutable shape produced by the deployed older serializer.
        DB::table('shift_report_revision_snapshots')->where('id', $snapshot->id)->update(['snapshot_data' => json_encode($legacyData)]);
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/handover/reject", ['rejection_reason' => 'Recount'])->assertOk();
        if ($edit) {
            $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/handover/edit", ['handover_amount' => '450.00', 'handover_notes' => 'New note', 'correction_reason' => 'input_error'])->assertOk();
        } else {
            $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end-with-handover", [
                'total_sales' => '500.00', 'cash_collected' => '450.00', 'counted_cash' => '450.00',
                'handover_to_type' => 'branch_manager', 'branch_manager_id' => $this->manager->id, 'handover_amount' => '450.00', 'handover_notes' => 'New note',
                'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id, 'amount' => '50.00']],
                'variance' => ['responsibility_type' => 'self', 'reason' => 'Corrected explanation'],
            ])->assertOk();
        }
        $history = CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->where('action', 'handover_request_corrected')->latest('id')->first();
        $this->assertNotNull($history, 'Corrections must preserve original request evidence even when its existing snapshot is incomplete.');
        $evidence = $history->new_value;
        $this->assertSame('Original request note', $evidence['previous_handover']['handover_notes'] ?? null);
        $this->assertSame('Original explanation', $evidence['previous_handover']['variance_reason'] ?? null);
        $this->assertSame([$file], $evidence['previous_handover']['variance_files'] ?? null);
        $this->assertSame('Original report note', $evidence['previous_report_projection']['handover_notes'] ?? null);
        $this->assertSame($legacyData, $snapshot->fresh()->snapshot_data);
        Storage::disk('public')->assertExists($file);
    }
}
