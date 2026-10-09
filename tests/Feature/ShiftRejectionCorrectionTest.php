<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Modules\Shift\Services\ShiftCashCountService;
use Tests\TestCase;

/** S1-10 corrections D14, D18, D19: rejection, revert and same-request correction. */
class ShiftRejectionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $recipient;

    private CashierShift $senderShift;

    private CashierShift $recipientShift;

    protected function setUp(): void
    {
        parent::setUp();
        $company = AsabCompany::create(['name' => 'Rej Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->sender = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->recipient = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->senderShift = $this->liveShift($this->sender, '06:00:00', '14:00:00');
        $this->recipientShift = $this->liveShift($this->recipient, '14:00:00', '22:00:00');
    }

    private function liveShift(Cashier $cashier, string $start, string $end): CashierShift
    {
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'start_time' => $start, 'end_time' => $end]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);

        return $shift->fresh();
    }

    private function d11Reject(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '500.00',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();
    }

    private function shortagePayload(): array
    {
        return [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '90.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->sender->id, 'amount' => '10.00']],
        ];
    }

    /** T1 (D18): plain recipient rejection voids the count; a shortage re-end supersedes the allocation. */
    public function test_plain_recipient_rejection_voids_count_and_allows_shortage_re_end(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", $this->shortagePayload() + [
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '90.00',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'wrong',
        ])->assertOk();

        $this->assertNull(app(ShiftCashCountService::class)->reconciliation($this->senderShift->id), 'rejected report must not stay current');
        $this->assertNull(app(ShiftCashCountService::class)->currentFor($this->senderShift->id));

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end", $this->shortagePayload())->assertOk();

        $versions = ShiftLiabilityAllocation::where('cashier_shift_id', $this->senderShift->id)->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertNotNull($versions[0]->superseded_at);
        $this->assertNull($versions[1]->superseded_at);
        $this->assertSame(-1000, $versions[1]->variance_halalas);
        $this->assertEquals(-10, app(ShiftCashCountService::class)->reconciliation($this->senderShift->id)['cash_variance']);
        // History is preserved: both counts still exist.
        $this->assertSame(2, ShiftReportCashCount::where('cashier_shift_id', $this->senderShift->id)->distinct()->count('counted_revision_id'));
    }

    /** T1b (D18): first manager rejection of a manager-addressed handover behaves the same. */
    public function test_manager_first_rejection_voids_count_and_allows_shortage_re_end(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", $this->shortagePayload() + [
            'handover_to_type' => 'branch_manager', 'handover_amount' => '90.00',
        ])->assertOk();
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'wrong',
        ])->assertOk();

        $this->assertNull(app(ShiftCashCountService::class)->reconciliation($this->senderShift->id));
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end", $this->shortagePayload())->assertOk();
        $this->assertSame(2, ShiftLiabilityAllocation::where('cashier_shift_id', $this->senderShift->id)->count());
    }

    /** T2 (D14): a plain rejection of a request that carries D11 evidence is a 409, not a 500, and writes nothing. */
    public function test_plain_rejection_after_d11_evidence_is_conflict_without_writes(): void
    {
        $this->d11Reject();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();
        $evidence = ShiftTransferRejectionEvidence::count();
        $handovers = CashierShiftHandover::count();

        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'plain',
        ])->assertStatus(409)->assertJsonPath('code', 'HANDOVER_HAS_CORRECTION_EVIDENCE');

        $this->assertSame($evidence, ShiftTransferRejectionEvidence::count());
        $this->assertSame($handovers, CashierShiftHandover::count());
        $this->assertSame(ShiftStatus::COMPLETED, $this->senderShift->fresh()->status);
    }

    /** T3 (D14): a new request after a D11 rejection is refused; correcting the same request reconciles to 0. */
    public function test_new_request_after_d11_rejection_is_refused_and_edit_path_reconciles(): void
    {
        $this->d11Reject();

        foreach (['start-handover', 'handover'] as $route) {
            $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/{$route}", [
                'next_cashier_id' => $this->recipient->id, 'handover_amount' => '480.00', 'handover_to_type' => 'cashier',
            ])->assertStatus(409)->assertJsonPath('code', 'HANDOVER_CORRECTION_PENDING');
        }
        $this->assertSame(1, CashierShiftHandover::count());

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/edit", [
            'handover_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/accept", [
            'confirmed_amount' => '480.00', 'receiving_shift_id' => $this->recipientShift->id,
        ])->assertOk();

        $end = $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '580.00',
        ])->assertOk();
        $recon = $end->json('data.summary.cash_reconciliation');
        $this->assertEquals(0, $recon['cash_variance']);
        $this->assertEquals(0, $recon['pending_incoming_cash']);
        $this->assertEquals(480, $recon['confirmed_opening_cash']);
    }

    /** T8 (D19): unattributed evidence belongs to the recipient's first later shift in the same branch only. */
    public function test_unattributed_evidence_is_attributed_to_first_later_shift_in_same_branch(): void
    {
        $service = app(ShiftCashCountService::class);
        $laterA = $this->liveShift($this->recipient, '22:00:00', '23:00:00');
        $laterB = $this->liveShift($this->recipient, '23:00:00', '23:30:00');
        $laterA->update(['actual_start_time' => now()->addHour()]);
        $laterB->update(['actual_start_time' => now()->addHours(2)]);
        $this->recipientShift->update(['actual_start_time' => now()->subHours(3)]);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id, 'handover_to_type' => 'cashier', 'handover_to_id' => $this->recipient->id,
            'handover_amount' => '500.00', 'status' => 'rejected', 'handover_date' => today()->toDateString(), 'handover_time' => now(),
        ]);
        ShiftTransferRejectionEvidence::create([
            'cashier_shift_handover_id' => $handover->id, 'recipient_type' => 'cashier', 'recipient_id' => $this->recipient->id,
            'receiving_cashier_shift_id' => null, 'requested_halalas' => 50000, 'physical_halalas' => 48000,
            'correction_reason' => 'actual_shortage', 'rejected_at' => now()->subHour(),
        ]);

        // Rejected an hour ago, after the recipient's current shift started and before the later shifts.
        $this->assertSame(0, $service->pendingIncomingHalalas($this->recipientShift->fresh()), 'started 3h ago: already open at rejection, so evidence is attributed elsewhere');
        $this->assertSame(48000, $service->pendingIncomingHalalas($laterA->fresh()));
        $this->assertSame(0, $service->pendingIncomingHalalas($laterB->fresh()), 'never subtracted from a second shift');

        // A shift of the same cashier on another branch's template never receives it.
        $otherBranch = Branch::factory()->create();
        $foreignTemplate = Shift::factory()->create(['branch_id' => $otherBranch->id, 'is_active' => true, 'start_time' => '01:00:00', 'end_time' => '02:00:00']);
        $foreign = CashierShift::create([
            'cashier_id' => $this->recipient->id, 'shift_id' => $foreignTemplate->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::IN_PROGRESS->value, 'actual_start_time' => now()->addMinutes(30),
        ]);
        $this->assertSame(0, $service->pendingIncomingHalalas($foreign->fresh()));
    }

    public function test_unattributed_cashier_evidence_keeps_its_source_branch_after_recipient_moves_company(): void
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->senderShift->id, 'handover_to_type' => 'cashier', 'handover_to_id' => $this->recipient->id,
            'handover_amount' => '500.00', 'status' => 'rejected', 'handover_date' => today()->toDateString(), 'handover_time' => now(),
        ]);
        ShiftTransferRejectionEvidence::create([
            'cashier_shift_handover_id' => $handover->id, 'recipient_type' => 'cashier', 'recipient_id' => $this->recipient->id,
            'requested_halalas' => 50000, 'physical_halalas' => 48000, 'correction_reason' => 'actual_shortage', 'rejected_at' => now(),
        ]);
        $this->assertSourceBranchOwnershipAfterMove();
    }

    public function test_unattributed_manager_transfer_evidence_keeps_its_source_branch_after_recipient_moves_company(): void
    {
        $source = \Modules\Shift\Models\BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())->firstOrFail();
        $transfer = \Modules\Shift\Models\BranchManagerCashTransfer::create([
            'branch_manager_shift_id' => $source->id, 'destination_cashier_id' => $this->recipient->id,
            'destination_cashier_shift_id' => $this->recipientShift->id, 'created_by_id' => $this->manager->id,
            'requested_amount' => '500.00', 'status' => 'rejected',
        ]);
        ShiftTransferRejectionEvidence::create([
            'branch_manager_cash_transfer_id' => $transfer->id, 'recipient_type' => 'cashier', 'recipient_id' => $this->recipient->id,
            'requested_halalas' => 50000, 'physical_halalas' => 48000, 'correction_reason' => 'actual_shortage', 'rejected_at' => now(),
        ]);
        $this->assertSourceBranchOwnershipAfterMove();
    }

    private function assertSourceBranchOwnershipAfterMove(): void
    {
        $this->recipientShift->update(['actual_start_time' => now()->subHours(3)]);
        $notStarted = CashierShift::create([
            'cashier_id' => $this->recipient->id, 'shift_id' => $this->senderShift->shift_id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED,
        ]);
        $this->assertSame(0, app(ShiftCashCountService::class)->pendingIncomingHalalas($notStarted), 'Creation time is not evidence that the receiving shift started.');
        $company = AsabCompany::create(['name' => 'Other company', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Other brand', 'sub_status' => 'active', 'status' => 'active']);
        $branchB = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->recipient->update(['branch_id' => $branchB->id]);
        $templateB = Shift::factory()->create(['branch_id' => $branchB->id, 'is_active' => true]);
        $inB = CashierShift::create([
            'cashier_id' => $this->recipient->id, 'shift_id' => $templateB->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->addMinutes(30),
        ]);
        $firstA = $this->liveShift($this->recipient, '22:00:00', '23:00:00');
        $firstA->update(['actual_start_time' => now()->addHour()]);
        $secondA = $this->liveShift($this->recipient, '23:00:00', '23:30:00');
        $secondA->update(['actual_start_time' => now()->addHours(2)]);
        $service = app(ShiftCashCountService::class);
        $this->assertSame(0, $service->pendingIncomingHalalas($inB->fresh()), 'Current membership in B does not move evidence out of A.');
        $this->assertSame(48000, $service->pendingIncomingHalalas($firstA->fresh()));
        $this->assertSame(0, $service->pendingIncomingHalalas($secondA->fresh()));

        // Equal start timestamps still have one deterministic owner (ascending shift ID).
        $secondA->update(['actual_start_time' => $firstA->fresh()->actual_start_time]);
        $owner = strcmp($firstA->id, $secondA->id) < 0 ? $firstA : $secondA;
        $other = $owner->id === $firstA->id ? $secondA : $firstA;
        $this->assertSame(48000, $service->pendingIncomingHalalas($owner->fresh()));
        $this->assertSame(0, $service->pendingIncomingHalalas($other->fresh()));
    }

    public function test_manager_correction_conflict_does_not_store_rejection_uploads(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '100.00', 'counted_cash' => '100.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '100.00',
        ])->assertOk();
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->sole();
        ShiftTransferRejectionEvidence::create([
            'cashier_shift_handover_id' => $handover->id, 'recipient_type' => 'branch_manager', 'recipient_id' => $this->manager->id,
            'requested_halalas' => 10000, 'physical_halalas' => 9000, 'correction_reason' => 'actual_shortage', 'rejected_at' => now(),
        ]);
        $before = $handover->fresh()->getAttributes();
        $this->actingAs($this->manager, 'sanctum')->post("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'plain rejection',
            'rejection_files' => [\Illuminate\Http\UploadedFile::fake()->create('count.pdf', 1, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'HANDOVER_HAS_CORRECTION_EVIDENCE');
        $this->assertSame($before, $handover->fresh()->getAttributes());
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }

    public function test_failed_rejection_after_upload_cleans_only_attempt_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('handover_rejections/approved.pdf', 'old evidence');
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '100.00', 'counted_cash' => '100.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '100.00',
        ])->assertOk();
        $handover = CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->sole();
        $status = $this->senderShift->fresh()->handoverStatus;
        $status->update(['rejection_files' => ['handover_rejections/approved.pdf']]);
        $before = $status->fresh()->getAttributes();
        \Modules\Shift\Models\ShiftHandoverStatus::updating(static function () {
            throw new \RuntimeException('injected rejection DB failure after upload');
        });
        try {
            $this->actingAs($this->manager, 'sanctum')->post("/api/v1/branch-manager/shifts/{$this->senderShift->id}/handover/reject", [
                'rejection_reason' => 'recount',
                'rejection_files' => [\Illuminate\Http\UploadedFile::fake()->create('new.pdf', 1, 'application/pdf')],
            ], ['Accept' => 'application/json'])->assertStatus(500);
        } finally {
            \Modules\Shift\Models\ShiftHandoverStatus::flushEventListeners();
        }
        $this->assertSame($before, $status->fresh()->getAttributes());
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(['handover_rejections/approved.pdf'], \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }

    public function test_second_upload_failure_removes_first_file_and_preserves_old_evidence(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('handover_rejections/old.pdf', 'old');
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '100.00', 'counted_cash' => '100.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '100.00',
        ])->assertOk();
        $failedFile = \Mockery::mock(\Illuminate\Http\UploadedFile::class);
        $failedFile->shouldReceive('getClientOriginalExtension')->andReturn('pdf');
        $failedFile->shouldReceive('storeAs')->once()->andThrow(new \RuntimeException('second upload failed'));
        try {
            app(\Modules\Shift\Services\HandoverService::class)->rejectHandover(
                $this->senderShift->fresh(), $this->manager->id, 'branch_manager', 'recount', [
                    \Illuminate\Http\UploadedFile::fake()->create('first.pdf', 1, 'application/pdf'), $failedFile,
                ]
            );
            $this->fail('The second upload must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('second upload failed', $e->getMessage());
        }
        $this->assertSame(['handover_rejections/old.pdf'], \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
        $this->assertSame('pending', CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->sole()->status);
        // A subsequent successful attempt retains its evidence; cleanup must not run after commit.
        app(\Modules\Shift\Services\HandoverService::class)->rejectHandover(
            $this->senderShift->fresh(), $this->manager->id, 'branch_manager', 'recount', [
                \Illuminate\Http\UploadedFile::fake()->create('success.pdf', 1, 'application/pdf'),
            ]
        );
        $this->assertCount(2, \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }
}
