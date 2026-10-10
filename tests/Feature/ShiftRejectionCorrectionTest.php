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
}
