<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftCashCountService;
use Tests\TestCase;

class ShiftTransferPhysicalReturnTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->create(['cashier_id' => $sender->id, 'shift_id' => $template->id, 'shift_date' => today(), 'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHours(2)]);
        $destination = CashierShift::factory()->create(['cashier_id' => $recipient->id, 'shift_id' => $template->id, 'shift_date' => today(), 'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()->subHour()]);
        $this->actingAs($sender, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'next_cashier_id' => $recipient->id, 'handover_amount' => '500.00',
        ])->assertOk();

        return [$sender, $recipient, $source, $destination, CashierShiftHandover::where('cashier_shift_id', $source->id)->sole()];
    }

    private function present($sender, $handover, string $key = 'presentation-one'): string
    {
        return $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => (int) round((float) $handover->fresh()->handover_amount * 100), 'idempotency_key' => $key,
        ])->assertOk()->json('data.id');
    }

    private function reject($recipient, $source): void
    {
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/reject", [
            'rejection_reason' => 'Only 480 physically counted', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
            'transfer_attempt_id' => CashierShiftHandover::where('cashier_shift_id', $source->id)->sole()->current_transfer_attempt_id,
        ])->assertOk();
    }

    private function initiate($recipient, string $attempt, string $key = 'return-one', int $amount = 48000)
    {
        return $this->actingAs($recipient, 'sanctum')->postJson("/api/shift-transfer-attempts/{$attempt}/returns", [
            'returned_halalas' => $amount, 'reason' => 'Returning the rejected cash', 'idempotency_key' => $key,
        ]);
    }

    public function test_recipient_initiates_sender_confirms_and_only_confirmation_changes_possession(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $attempt = $this->present($sender, $handover);
        $this->assertSame($attempt, $this->present($sender, $handover));
        $this->reject($recipient, $source);
        $counts = app(ShiftCashCountService::class);
        $this->assertSame(48000, $counts->pendingIncomingHalalas($destination));
        $return = $this->initiate($recipient, $attempt)->assertOk()->json('data.id');
        $this->assertSame($return, $this->initiate($recipient, $attempt)->assertOk()->json('data.id'));
        $this->assertSame(48000, $counts->pendingIncomingHalalas($destination));
        $this->actingAs($recipient, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertForbidden();
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->assertSame(0, $counts->pendingIncomingHalalas($destination));
        $this->assertDatabaseCount('shift_transfer_returns', 1);
        $this->assertDatabaseCount('cashier_shift_handover_receipts', 0);
        $this->assertSame('0.00', $destination->fresh()->opening_balance);
        $this->assertSame(48000, DB::table('shift_transfer_rejection_evidence')->sole()->physical_halalas);
    }

    public function test_legacy_null_attempt_is_preserved_and_cannot_manufacture_a_return(): void
    {
        [, $recipient, $source, $destination] = $this->fixture();
        $this->reject($recipient, $source);
        $this->assertNull(DB::table('shift_transfer_rejection_evidence')->sole()->transfer_attempt_id);
        $this->assertSame(48000, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
    }

    public function test_correction_needs_new_physical_presentation_and_old_return_cannot_suppress_it(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $first = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $return = $this->initiate($recipient, $first)->assertOk()->json('data.id');
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->postJson("/api/cashier/shifts/{$source->id}/handover/edit", ['handover_amount' => '480.00', 'correction_reason' => 'actual_shortage'])->assertOk();
        $this->assertDatabaseCount('shift_transfer_attempts', 1);
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/accept", ['confirmed_amount' => '480.00', 'receiving_shift_id' => $destination->id])->assertConflict();
        $second = $this->present($sender, $handover, 'physical-redelivery');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('shift_transfer_attempts')->where('id', $second)->value('sequence'));
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/reject", ['rejection_reason' => 'Delayed old presentation rejection', 'confirmed_amount' => '470.00', 'correction_reason' => 'actual_shortage', 'transfer_attempt_id' => $first])->assertConflict();
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/accept", ['confirmed_amount' => '480.00', 'receiving_shift_id' => $destination->id, 'transfer_attempt_id' => $first])->assertConflict();
        $this->postJson("/api/cashier/shifts/{$source->id}/handover/accept", ['confirmed_amount' => '480.00', 'receiving_shift_id' => $destination->id, 'transfer_attempt_id' => $second])->assertOk();
        $this->assertSame($second, DB::table('cashier_shift_handover_receipts')->sole()->transfer_attempt_id);
        $this->assertSame('480.00', $destination->fresh()->opening_balance);
        $this->assertSame(0, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
        $this->initiate($recipient, $first, 'stale-return', 1)->assertConflict();
        $this->initiate($recipient, $second, 'receipt-already-confirmed', 1)->assertConflict();
        $this->assertSame(50000, DB::table('shift_transfer_attempts')->where('id', $first)->value('presented_halalas'));
    }

    public function test_new_rejected_attempt_counts_its_own_physical_cash_after_prior_return(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $first = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $return = $this->initiate($recipient, $first)->assertOk()->json('data.id');
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->postJson("/api/cashier/shifts/{$source->id}/handover/edit", ['handover_amount' => '480.00', 'correction_reason' => 'actual_shortage'])->assertOk();
        $second = $this->present($sender, $handover, 'second');
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$source->id}/handover/reject", ['rejection_reason' => 'Second presentation short', 'confirmed_amount' => '470.00', 'correction_reason' => 'actual_shortage', 'transfer_attempt_id' => $second])->assertOk();
        $this->assertSame(47000, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
        $this->assertDatabaseHas('shift_transfer_rejection_evidence', ['transfer_attempt_id' => $second, 'physical_halalas' => 47000]);
    }

    public function test_pending_returns_cannot_over_reserve_and_payload_mismatch_conflicts(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $attempt = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $first = $this->initiate($recipient, $attempt, 'partial', 20000)->assertOk()->json('data.id');
        $this->initiate($recipient, $attempt, 'partial', 10000)->assertConflict();
        $this->initiate($recipient, $attempt, 'too-much', 30000)->assertConflict();
        $second = $this->initiate($recipient, $attempt, 'remaining', 28000)->assertOk()->json('data.id');
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$first}/confirm")->assertOk();
        $this->assertSame(28000, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
        $this->postJson("/api/shift-transfer-returns/{$second}/confirm")->assertOk();
        $this->assertSame(0, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
    }

    public function test_confirmed_return_after_count_preserves_evidence_and_requires_fresh_count(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $attempt = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$destination->id}/end", ['total_sales' => '0.00', 'cash_collected' => '0.00', 'counted_cash' => '480.00'])->assertOk();
        $counts = app(ShiftCashCountService::class);
        $old = $counts->currentFor($destination->id);
        $this->assertSame(48000, $old->counted_halalas);
        $historical = $old->getAttributes();
        $revisionCount = DB::table('shift_report_revisions')->count();
        $return = $this->initiate($recipient, $attempt)->assertOk()->json('data.id');
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->assertSame($historical, $old->fresh()->getAttributes());
        $this->assertNull($counts->currentFor($destination->id));
        $this->assertSame($revisionCount + 1, DB::table('shift_report_revisions')->count());
        $this->assertDatabaseHas('shift_report_aggregates', ['source_id' => $destination->id, 'fresh_count_required' => true]);
        $this->actingAs($recipient, 'sanctum')->postJson("/api/cashier/shifts/{$destination->id}/handover", ['handover_amount' => '0.00', 'next_cashier_id' => $sender->id])->assertConflict();
        $this->postJson("/api/shift-reports/{$destination->id}/recount", ['counted_halalas' => 0])->assertOk();
        $this->assertSame(0, $counts->currentFor($destination->id)->counted_halalas);
        $this->assertDatabaseHas('shift_report_aggregates', ['source_id' => $destination->id, 'fresh_count_required' => false]);
        $this->assertSame($historical, $old->fresh()->getAttributes());
    }

    public function test_closed_day_returns_report_reopen_required_without_any_mutation(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $attempt = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $return = $this->initiate($recipient, $attempt)->assertOk()->json('data.id');
        $outgoing = CashierShiftHandover::create(['cashier_shift_id' => $destination->id, 'handover_to_type' => 'cashier', 'handover_to_id' => $sender->id, 'handover_amount' => 0, 'variance_amount' => 0, 'handover_date' => today(), 'handover_time' => now(), 'status' => 'pending', 'daily_closed_at' => now()]);
        $before = DB::table('shift_report_revisions')->count();
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertConflict()->assertJsonPath('message', 'REPORT_REOPEN_REQUIRED');
        $this->assertNull(DB::table('shift_transfer_returns')->where('id', $return)->value('sender_confirmed_at'));
        $this->assertSame($before, DB::table('shift_report_revisions')->count());
        $this->assertNotNull($outgoing->fresh()->daily_closed_at);
        $this->assertSame(48000, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
    }

    public function test_historical_branch_company_and_participant_identity_survive_current_branch_changes(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $branch = $source->shift->branch_id;
        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'Attempt Co', 'plan' => 'Professional', 'status' => 'active']);
        DB::table('branches')->where('id', $branch)->update(['asab_company_id' => $company->id]);
        $attempt = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $return = $this->initiate($recipient, $attempt)->assertOk()->json('data.id');
        $other = Branch::factory()->create();
        $sender->update(['branch_id' => $other->id]);
        $recipient->update(['branch_id' => $other->id]);
        $this->actingAs($sender->fresh(), 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->assertDatabaseHas('shift_transfer_attempts', ['id' => $attempt, 'source_branch_id' => $branch, 'source_company_id' => $company->id, 'sender_id' => $sender->id, 'recipient_id' => $recipient->id]);
        $this->assertSame(0, app(ShiftCashCountService::class)->pendingIncomingHalalas($destination));
    }

    public function test_manager_transfer_uses_same_typed_physical_return_and_redelivery_http_contract(): void
    {
        [$sender, $recipient, $source, $destination] = $this->fixture();
        $manager = BranchManager::findOrFail($sender->created_by);
        $workday = \Modules\Shift\Models\BranchManagerShift::where('branch_manager_id', $manager->id)->whereDate('shift_date', today())->firstOrFail();
        \Modules\Custody\Models\PersonalLedgerTransaction::create(['branch_manager_id' => $manager->id, 'branch_manager_shift_id' => $workday->id, 'transaction_type' => 'Total Sales', 'amount' => '500.00', 'is_cash_in' => true, 'transaction_date' => now()]);
        $service = app(\Modules\Shift\Services\ShiftTransferReceiptService::class);
        $request = $service->requestManagerCashTransfer($workday, $recipient, $destination, '500.00', $manager);
        $first = $this->actingAs($manager, 'sanctum')->postJson("/api/shift-transfers/manager_transfer/{$request->id}/present", ['presented_halalas' => 50000, 'idempotency_key' => 'manager-presentation'])->assertOk()->json('data.id');
        $this->actingAs($recipient, 'sanctum')->postJson("/api/shift-transfer-attempts/{$first}/reject", ['physical_halalas' => 48000, 'reason' => 'Manager transfer physical shortage', 'correction_reason' => 'actual_shortage'])->assertOk();
        $return = $this->initiate($recipient, $first)->assertOk()->json('data.id');
        $this->actingAs($manager, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $service->correctManagerCashTransfer($request->id, $manager, '480.00', 'actual_shortage');
        $second = $this->postJson("/api/shift-transfers/manager_transfer/{$request->id}/present", ['presented_halalas' => 48000, 'idempotency_key' => 'manager-redelivery'])->assertOk()->json('data.id');
        $this->actingAs($recipient, 'sanctum')->postJson("/api/shift-transfer-attempts/{$first}/confirm-receipt", ['confirmed_halalas' => 48000])->assertConflict();
        $this->postJson("/api/shift-transfer-attempts/{$second}/confirm-receipt", ['confirmed_halalas' => 48000])->assertOk();
        $this->assertDatabaseHas('cashier_shift_handover_receipts', ['transfer_attempt_id' => $second, 'branch_manager_cash_transfer_id' => $request->id]);
        $this->assertSame('480.00', $destination->fresh()->opening_balance);
    }

    public function test_recount_uses_latest_revision_channels_when_count_timestamps_tie(): void
    {
        [$sender, $recipient, $source, $destination, $handover] = $this->fixture();
        $attempt = $this->present($sender, $handover);
        $this->reject($recipient, $source);
        $counts = app(ShiftCashCountService::class);
        $revisions = app(\Modules\Shift\Services\ShiftReportRevisionService::class);
        // Seed successive immutable count evidence with the same server timestamp; the HTTP return and
        // recount writers must select the most recent report revision, not an arbitrary timestamp tie.
        $this->freezeTime();
        DB::transaction(function () use ($destination, $recipient, $counts, $revisions) {
            $first = $revisions->recordCashierRevision($destination, 'cashier', $recipient->id);
            $counts->record($destination, $first, 0, 0, 0, 48000);
            $second = $revisions->recordCashierRevision($destination, 'cashier', $recipient->id);
            $counts->record($destination, $second, 100, 0, 0, 48100);
        });
        $return = $this->initiate($recipient, $attempt)->assertOk()->json('data.id');
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertOk();
        $this->actingAs($recipient, 'sanctum')->postJson("/api/shift-reports/{$destination->id}/recount", ['counted_halalas' => 100])->assertOk();
        $this->assertSame(100, $counts->currentFor($destination->id)->gross_halalas);
        $this->assertSame(0, $counts->currentFor($destination->id)->variance_halalas);
    }

    public function test_manager_recipient_return_checks_rejection_workday_after_midnight(): void
    {
        [$sender, $recipient, $source, , $handover] = $this->fixture();
        $manager = BranchManager::findOrFail($sender->created_by);
        $handover->update(['handover_to_type' => 'branch_manager', 'handover_to_id' => $manager->id]);
        $attempt = $this->present($sender, $handover);
        $this->travelTo(now()->addDay()->startOfDay()->addHour());
        $this->actingAs($manager, 'sanctum')->postJson("/api/shift-transfer-attempts/{$attempt}/reject", ['physical_halalas' => 48000, 'reason' => 'Rejected after midnight', 'correction_reason' => 'actual_shortage'])->assertOk();
        $return = $this->initiate($manager, $attempt)->assertOk()->json('data.id');
        \Modules\Shift\Models\BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $manager->branch_id, 'shift_date' => today(), 'status' => 'completed', 'daily_report_submitted' => true]);
        $this->actingAs($sender, 'sanctum')->postJson("/api/shift-transfer-returns/{$return}/confirm")->assertConflict()->assertJsonPath('message', 'REPORT_REOPEN_REQUIRED');
        $this->assertNull(DB::table('shift_transfer_returns')->where('id', $return)->value('sender_confirmed_at'));
    }
}
